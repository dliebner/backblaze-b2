<?php

namespace dliebner\B2;

use dliebner\B2\Exceptions\CacheException;
use dliebner\B2\Exceptions\NotFoundException;
use dliebner\B2\Exceptions\ValidationException;
use dliebner\B2\Http\Client as HttpClient;
use Illuminate\Cache\CacheManager;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;
use Psr\Http\Message\ResponseInterface;

class Client
{
    protected $accountId;

    /**
     * @var \Illuminate\Contracts\Cache\Repository
     */
    protected $cache;

    protected $authToken;
    protected $apiUrl = '';
    public $downloadUrl;
    protected $recommendedPartSize;

    protected $authorizationValues;

    protected $client;

    public $version = 1;

    /**
     * If you setup CNAME records to point to backblaze servers (for white-label service)
     * assign this property with the equivalent URLs
     * ['f0001.backblazeb2.com' => 'alias01.mydomain.com']
     * @var array
     */
    public $domainAliases = [];

    /**
     * Lower limit for using large files upload support. More information:
     * https://www.backblaze.com/b2/docs/large_files.html. Default: 3 GB
     * Files larger than this value will be uploaded in multiple parts.
     *
     * @var int
     */
    public $largeFileLimit = 3000000000;

    /**
     * Seconds to remeber authorization
     * @var int
     */
    public $authorizationCacheTime = 60;

    /**
     * Client constructor. Accepts the account ID, application key and an optional array of options.
     * @param string $accountId
     * @param array $authorizationValues
     * @param array $options
     * @throws CacheException
     */
    public function __construct(string $accountId, array $authorizationValues, array $options = [])
    {
        $this->accountId = $accountId;

        if (!isset($authorizationValues['keyId']) or empty($authorizationValues['keyId'])) {
            $authorizationValues['keyId'] = $accountId;
        }

        if (empty($authorizationValues['keyId']) or empty($authorizationValues['applicationKey'])) {
            throw new \Exception('Please provide "keyId" and "applicationKey"');
        }


        if (isset($options['client'])) {
            $this->client = $options['client'];
        } else {
            $this->client = new HttpClient(['http_errors' => false]);
        }

        // initialize cache
        $this->createCacheContainer();

        $this->authorizationValues = $authorizationValues;

        $this->authorizeAccount(false);
    }

    private function createCacheContainer()
    {
        $container = new Container;

        $container['config'] = [
            'cache.default' => 'redis',
            'cache.stores.redis' => [
                'driver' => 'redis',
                'connection' => 'default'
            ],
            'cache.prefix' => 'torch',
            'database.redis' => [
                'cluster' => false,
                'default' => [
                    'host' => '127.0.0.1',
                    'port' => 6379,
                    'database' => 0,
                ],
            ]
        ];
    
        $container['redis'] = new RedisManager($container, 'phpredis', $container['config']['database.redis']);

        try {
            $cacheManager = new CacheManager($container);
            $this->cache = $cacheManager->store();
        } catch (\Exception $e) {
            throw new CacheException(
                $e->getMessage()
            );
        }
    }

    /**
     * Create a bucket with the given name and type.
     *
     * @param array $options
     * @return Bucket
     * @throws ValidationException
     */
    public function createBucket(array $options)
    {
        if (!in_array($options['BucketType'], [Bucket::TYPE_PUBLIC, Bucket::TYPE_PRIVATE])) {
            throw new ValidationException(
                sprintf('Bucket type must be %s or %s', Bucket::TYPE_PRIVATE, Bucket::TYPE_PUBLIC)
            );
        }

        $response = $this->request('POST', '/b2_create_bucket', [
            'json' => [
                'accountId' => $this->accountId,
                'bucketName' => $options['BucketName'],
                'bucketType' => $options['BucketType'],
            ],
        ]);

        return new Bucket($response);
    }

    /**
     * Updates the type attribute of a bucket by the given ID.
     *
     * @param array $options
     * @return Bucket
     * @throws ValidationException
     */
    public function updateBucket(array $options)
    {
        if (!in_array($options['BucketType'], [Bucket::TYPE_PUBLIC, Bucket::TYPE_PRIVATE])) {
            throw new ValidationException(
                sprintf('Bucket type must be %s or %s', Bucket::TYPE_PRIVATE, Bucket::TYPE_PUBLIC)
            );
        }

        if (!isset($options['BucketId']) && isset($options['BucketName'])) {
            $options['BucketId'] = $this->getBucketIdFromName($options['BucketName']);
        }

        $response = $this->request('POST', '/b2_update_bucket', [
            'json' => [
                'accountId' => $this->accountId,
                'bucketId' => $options['BucketId'],
                'bucketType' => $options['BucketType'],
            ],
        ]);

        return new Bucket($response);
    }

    /**
     * Returns a list of bucket objects representing the buckets on the account.
     *
     * @return Bucket[]
     */
    public function listBuckets($refresh = false)
    {
        $cacheKey = 'B2-SDK-Buckets';
        $bucketsObj = [];
        if (!$this->cache->has($cacheKey)) {
            $refresh = true;
        }
        if ($refresh === true) {
            $buckets = $this->request('POST', '/b2_list_buckets', [
                'json' => [
                    'accountId' => $this->accountId,
                ],
            ])['buckets'];
            $this->cache->set($cacheKey, $buckets, 10080);
        } else {
            $buckets = $this->cache->get($cacheKey);
        }

        foreach ($buckets as $bucket) {
            $bucketsObj[] = new Bucket($bucket);
        }

        return $bucketsObj;
    }

    /**
     * Deletes the bucket identified by its ID.
     *
     * @param array $options
     * @return bool
     */
    public function deleteBucket(array $options)
    {
        if (!isset($options['BucketId']) && isset($options['BucketName'])) {
            $options['BucketId'] = $this->getBucketIdFromName($options['BucketName']);
        }

        $this->request('POST', '/b2_delete_bucket', [
            'json' => [
                'accountId' => $this->accountId,
                'bucketId' => $options['BucketId'],
            ],
        ]);

        return true;
    }

    /**
     * Uploads a file to a bucket and returns a File object.
     *
     * @param array $options
     * @return File
     */
    public function upload(array $options)
    {
        // Clean the path if it starts with /.
        if (substr($options['FileName'], 0, 1) === '/') {
            $options['FileName'] = ltrim($options['FileName'], '/');
        }

        if (!isset($options['BucketId']) && isset($options['BucketName'])) {
            $options['BucketId'] = $this->getBucketIdFromName($options['BucketName']);
        }

        if (!isset($options['FileLastModified'])) {
            $options['FileLastModified'] = round(microtime(true) * 1000);
        }

        if (!isset($options['FileContentType'])) {
            $options['FileContentType'] = 'b2/x-auto';
        }

        list($options['hash'], $options['size']) = $this->getFileHashAndSize($options['Body']);

        if ($options['size'] <= $this->largeFileLimit && $options['size'] <= $this->recommendedPartSize) {
            return $this->uploadStandardFile($options);
        } else {
            return $this->uploadLargeFile($options);
        }
    }

    /**
     * @param $bucket
     * @param $path
     * @param int $validDuration
     * @return string
     */
    public function getDownloadAuthorization($bucket, $path, $validDuration = 60)
    {
        if ($bucket instanceof Bucket) {
            $bucketId = $bucket->getId();
        } else {
            $bucketId = $bucket;
        }

        $response = $this->request('POST', '/b2_get_download_authorization', [
            'json' => [
                'bucketId' => $bucketId,
                'fileNamePrefix' => $path,
                'validDurationInSeconds' => $validDuration,
            ],
        ]);

        return $response['authorizationToken'];
    }

    /**
     * @param Bucket|string $bucket
     * @param string $filePath
     * @param bool $appendToken
     * @param int $tokenTimeout
     * @return string
     */
    public function getDownloadUrl($bucket, string $filePath, $appendToken = false, $tokenTimeout = 60)
    {
        if (!$bucket instanceof Bucket) {
            $bucket = $this->getBucketFromId($bucket);
        }

        $path = $this->downloadUrl . '/file/' . $bucket->getName() . '/' . $filePath;

        if ($appendToken) {
            $path .= '?Authorization='
                . $this->getDownloadAuthorization($bucket, dirname($filePath) . '/', $tokenTimeout);
        }

        return strtr($path, $this->domainAliases);
    }

    /**
     * @param File $file
     * @param bool $appendToken
     * @param int $tokenTimeout
     * @return string
     */
    public function getDownloadUrlForFile(File $file, $appendToken = false, $tokenTimeout = 60)
    {
        return $this->getDownloadUrl($file->getBucketId(), $file->getFileName(), $appendToken, $tokenTimeout);
    }

    /**
     * Download a file from a B2 bucket.
     *
     * @param array $options
     * @return bool|mixed|string
     */
    public function download(array $options)
    {
        $requestUrl = null;
        $requestOptions = [
            'sink' => isset($options['SaveAs']) ? $options['SaveAs'] : fopen('php://temp', 'w'),
        ];

        if (isset($options['FileId'])) {
            $requestOptions['query'] = ['fileId' => $options['FileId']];
            $requestUrl = $this->downloadUrl . '/b2api/v1/b2_download_file_by_id';
        } else {
            if (!isset($options['BucketName']) && isset($options['BucketId'])) {
                $options['BucketName'] = $this->getBucketNameFromId($options['BucketId']);
            }

            $requestUrl = $this->getB2FileRequestUrl($options['BucketName'], $options['FileName']);
        }

        if (isset($options['stream'])) {
            $requestOptions['stream'] = $options['stream'];
            $response = $this->request('GET', $requestUrl, $requestOptions, false);
        } else {
            $response = $this->request('GET', $requestUrl, $requestOptions, false);
        }

        return isset($options['SaveAs']) ? true : $response;
    }

    public function getB2FileRequestUrl($b2BucketName, $b2FileName) {

        return sprintf('%s/file/%s/%s', $this->downloadUrl, $b2BucketName, $b2FileName);

    }

    public function accelRedirectData(array $options)
    {
        $parsed = parse_url($this->downloadUrl);

        return [
            'host' => $parsed['host'],
            'query' => sprintf("fileId=%s", $options['FileId']),
        ];
    }

    /**
     * Retrieve a collection of File objects representing the files stored inside a bucket.
     *
     * @param array $options
     * @return array
     */
    public function listFiles(array $options)
    {
        // if FileName is set, we only attempt to retrieve information about that single file.
        $fileName = !empty($options['FileName']) ? $options['FileName'] : null;

        $nextFileName = null;
        $maxFileCount = 1000;
        $files = [];

        if (!isset($options['BucketId']) && isset($options['BucketName'])) {
            $options['BucketId'] = $this->getBucketIdFromName($options['BucketName']);
        }

        if ($fileName) {
            $nextFileName = $fileName;
            $maxFileCount = 1;
        }

        // B2 returns, at most, 1000 files per "page". Loop through the pages and compile an array of File objects.
        while (true) {
            $response = $this->request('POST', '/b2_list_file_names', [
                'json' => [
                    'bucketId' => $options['BucketId'],
                    'startFileName' => $nextFileName,
                    'maxFileCount' => $maxFileCount,
                ],
            ]);

            foreach ($response['files'] as $file) {
                // if we have a file name set, only retrieve information if the file name matches
                if (!$fileName || ($fileName === $file['fileName'])) {
                    $files[] = new File($file);
                }
            }

            if ($fileName || $response['nextFileName'] === null) {
                // We've got all the files - break out of loop.
                break;
            }

            $nextFileName = $response['nextFileName'];
        }

        return $files;
    }

    /**
     * Test whether a file exists in B2 for the given bucket.
     *
     * @param array $options
     * @return boolean
     */
    public function fileExists(array $options)
    {
        $files = $this->listFiles($options);

        return !empty($files);
    }

    /**
     * Returns a single File object representing a file stored on B2.
     *
     * @param array $options
     * @throws NotFoundException If no file id was provided and BucketName + FileName does not resolve to a file, a NotFoundException is thrown.
     * @return File
     */
    public function getFile(array $options)
    {
        if (!isset($options['FileId']) && isset($options['BucketName']) && isset($options['FileName'])) {
            $options['FileId'] = $this->getFileIdFromBucketAndFileName($options['BucketName'], $options['FileName']);

            if (!$options['FileId']) {
                throw new NotFoundException();
            }
        }

        $response = $this->request('POST', '/b2_get_file_info', [
            'json' => [
                'fileId' => $options['FileId'],
            ],
        ]);

        return new File($response);
    }

    /**
     * Deletes the file identified by ID from Backblaze B2.
     *
     * @param array $options
     * @return bool
     */
    public function deleteFile(array $options)
    {
        if (!isset($options['FileName'])) {
            $file = $this->getFile($options);

            $options['FileName'] = $file->getFileName();
        }

        if (!isset($options['FileId']) && isset($options['BucketName']) && isset($options['FileName'])) {
            $file = $this->getFile($options);

            $options['FileId'] = $file->getFileId();
        }

        $this->request('POST', '/b2_delete_file_version', [
            'json' => [
                'fileName' => $options['FileName'],
                'fileId' => $options['FileId'],
            ],
        ]);

        return true;
    }

    /**
     * Maps the provided bucket name to the appropriate bucket ID.
     *
     * @param $name
     * @return string|null
     */
    public function getBucketIdFromName($name)
    {
        $bucket = $this->getBucketFromName($name);

        if ($bucket instanceof Bucket) {
            return $bucket->getId();
        }

        return null;
    }

    /**
     * Maps the provided bucket ID to the appropriate bucket name.
     *
     * @param $id
     * @return string|null
     */
    public function getBucketNameFromId($id)
    {
        $bucket = $this->getBucketFromId($id);

        if ($bucket instanceof Bucket) {
            return $bucket->getName();
        }

        return null;
    }

    /**
     * @param $bucketId
     * @return Bucket|null
     */
    public function getBucketFromId($id)
    {
        $buckets = $this->listBuckets();

        foreach ($buckets as $bucket) {
            if ($bucket->getId() === $id) {
                return $bucket;
            }
        }

        return null;
    }

    /**
     * @param $name
     * @return Bucket|null
     */
    public function getBucketFromName($name)
    {

        $buckets = $this->listBuckets();

        foreach ($buckets as $bucket) {
            if ($bucket->getName() === $name) {
                return $bucket;
            }
        }

        return null;
    }

    protected function getFileIdFromBucketAndFileName($bucketName, $fileName)
    {
        $files = $this->listFiles([
            'BucketName' => $bucketName,
            'FileName' => $fileName,
        ]);

        foreach ($files as $file) {
            if ($file->getFileName() === $fileName) {
                return $file->getFileId();
            }
        }

        return null;
    }

    /**
     * Calculate hash and size of file/stream. If $offset and $partSize is given return
     * hash and size of this chunk
     *
     * @param $content
     * @param int $offset
     * @param null $partSize
     * @return array
     */
    public function getFileHashAndSize($data, $offset = 0, $partSize = null)
    {
        if (!$partSize) {
            if (is_resource($data)) {
                // We need to calculate the file's hash incrementally from the stream.
                $context = hash_init('sha1');
                hash_update_stream($context, $data);
                $hash = hash_final($context);
                // Similarly, we have to use fstat to get the size of the stream.
                $size = fstat($data)['size'];
                // Rewind the stream before passing it to the HTTP client.
                rewind($data);
            } else {
                // We've been given a simple string body, it's super simple to calculate the hash and size.
                $hash = sha1($data);
                $size = mb_strlen($data, '8bit');
            }
        } else {
            $dataPart = $this->getPartOfFile($data, $offset, $partSize);
            $hash = sha1($dataPart);
            $size = mb_strlen($dataPart, '8bit');
        }

        return array($hash, $size);
    }

    /**
     * Return selected part of file
     *
     * @param $data
     * @param $offset
     * @param $partSize
     * @return bool|string
     */
    protected function getPartOfFile($data, $offset, $partSize)
    {
        // Get size and hash of one data chunk
        if (is_resource($data)) {
            // Get data chunk
            fseek($data, $offset);
            $dataPart = fread($data, $partSize);
            // Rewind the stream before passing it to the HTTP client.
            rewind($data);
        } else {
            $dataPart = substr($data, $offset, $partSize);
        }
        return $dataPart;
    }

    /**
     * Upload single file (smaller than 3 GB)
     *
     * @param array $options
     * @return File
     */
    protected function uploadStandardFile($options = array())
    {
        // Retrieve the URL that we should be uploading to.
        $response = $this->request('POST', '/b2_get_upload_url', [
            'json' => [
                'bucketId' => $options['BucketId'],
            ],
        ]);

        $uploadEndpoint = $response['uploadUrl'];
        $uploadAuthToken = $response['authorizationToken'];

        $response = $this->request('POST', $uploadEndpoint, [
            'headers' => [
                'Authorization' => $uploadAuthToken,
                'Content-Type' => $options['FileContentType'],
                'Content-Length' => $options['size'],
                'X-Bz-File-Name' => $options['FileName'],
                'X-Bz-Content-Sha1' => $options['hash'],
                'X-Bz-Info-src_last_modified_millis' => $options['FileLastModified'],
            ],
            'body' => $options['Body'],
        ]);

        return new File($response);
    }

    /**
     * Upload large file. Large files will be uploaded in chunks of recommendedPartSize bytes (usually 100MB each)
     *
     * @param array $options
     * @return File
     */
    protected function uploadLargeFile($options)
    {
        // Prepare for uploading the parts of a large file.
        $response = $this->request('POST', '/b2_start_large_file', [
            'json' => [
                'bucketId' => $options['BucketId'],
                'fileName' => $options['FileName'],
                'contentType' => $options['FileContentType'],
                /**
                 * 'fileInfo' => [
                 * 'src_last_modified_millis' => $options['FileLastModified'],
                 * 'large_file_sha1' => $options['hash']
                 * ]
                 **/
            ],
        ]);
        $fileId = $response['fileId'];

        $partsCount = ceil($options['size'] / $this->recommendedPartSize);

        $hashParts = [];
        for ($i = 1; $i <= $partsCount; $i++) {
            $bytesSent = ($i - 1) * $this->recommendedPartSize;
            $bytesLeft = $options['size'] - $bytesSent;
            $partSize = ($bytesLeft > $this->recommendedPartSize) ? $this->recommendedPartSize : $bytesLeft;

            // Retrieve the URL that we should be uploading to.
            $response = $this->request('POST', '/b2_get_upload_part_url', [
                'json' => [
                    'fileId' => $fileId,
                ],
            ]);

            $uploadEndpoint = $response['uploadUrl'];
            $uploadAuthToken = $response['authorizationToken'];

            list($hash, $size) = $this->getFileHashAndSize($options['Body'], $bytesSent, $partSize);
            $hashParts[] = $hash;

            $response = $this->request('POST', $uploadEndpoint, [
                'headers' => [
                    'Authorization' => $uploadAuthToken,
                    'X-Bz-Part-Number' => $i,
                    'Content-Length' => $size,
                    'X-Bz-Content-Sha1' => $hash,
                ],
                'body' => $this->getPartOfFile($options['Body'], $bytesSent, $partSize),
            ]);
        }

        // Finish upload of large file
        $response = $this->request('POST', '/b2_finish_large_file', [
            'json' => [
                'fileId' => $fileId,
                'partSha1Array' => $hashParts,
            ],
        ]);
        return new File($response);
    }

    /**
     * @param Key $key
     * @throws \Exception
     */
    public function createKey($key)
    {
        throw new \Exception(__FUNCTION__ . ' has not been implemented yet');
    }

    /**
     * Authorize the B2 account in order to get an auth token and API/download URLs.
     * @param bool $forceRefresh
     */
    public function authorizeAccount($forceRefresh = false)
    {
        $keyId = $this->authorizationValues['keyId'];
        $applicationKey = $this->authorizationValues['applicationKey'];

        $baseApiUrl = 'https://api.backblazeb2.com';
        $versionPath = '/b2api/v' . $this->version;

        if ($forceRefresh === true) {
            $this->cache->forget('B2-SDK-Authorization');
        }

        $response = $this->cache->remember('B2-SDK-Authorization', $this->authorizationCacheTime,
            function () use ($keyId, $applicationKey, $baseApiUrl, $versionPath) {
                return $this->request('GET', $baseApiUrl . $versionPath . '/b2_authorize_account', [
                    'auth' => [$keyId, $applicationKey],
                ]);
            });

        $this->authToken = $response['authorizationToken'];
        $this->apiUrl = $response['apiUrl'] . $versionPath;
        $this->downloadUrl = $response['downloadUrl'];
        $this->recommendedPartSize = $response['recommendedPartSize'];
    }

    /**
     * Wrapper for $this->client->request
     * @param string $method
     * @param string $uri
     * @param array $options
     * @param bool $asJson
     * @param bool $wantsGetContents
     * @return mixed|string
     */
    protected function request($method, $uri = null, array $options = [], $asJson = true)
    {
        $headers = [];

        // Add Authorization token if defined
        if ($this->authToken) {
            $headers['Authorization'] = $this->authToken;
        }

        $options = array_replace_recursive([
            'headers' => $headers,
        ], $options);

        $fullUri = $uri;

        if (substr($uri, 0, 8) !== 'https://') {
            $fullUri = $this->apiUrl . $uri;
        }

        $response = $this->client->request($method, $fullUri, $options);

        if ($asJson) {
            return json_decode($response->getBody(), true);
        }

        return $response->getBody();
    }

    public function requestAsync($method, $uri = null, array $options = [])
    {
        $headers = [];

        // Add Authorization token if defined
        if ($this->authToken) {
            $headers['Authorization'] = $this->authToken;
        }

        $options = array_replace_recursive([
            'headers' => $headers,
        ], $options);

        $fullUri = $uri;

        if (substr($uri, 0, 8) !== 'https://') {
            $fullUri = $this->apiUrl . $uri;
        }

        return $this->client->requestAsync($method, $fullUri, $options);

    }

}

class ParallelUploader {

    public $client;

    public $bucketId;

    public $numUploadLanes = 7;

    public $filesToUpload = [];

    public $failedUnattemptedFiles = [];

    /** @var AsyncUploadLane[] */
    protected $stdUploadLanes = [];

    public function __construct(Client $client, $bucketId, $numUploadLanes = null) {

        $this->client = $client;

        $this->bucketId = $bucketId;

        if( $numUploadLanes ) $this->numUploadLanes = $numUploadLanes;
        
    }

    public function addFileToUpload($fileOptions) {

        $this->filesToUpload[] = $fileOptions;

    }

    public function getNextFile() {

        return array_shift($this->filesToUpload);

    }

    /** @return AsyncUploadFileResult[] */
    public function getAllUploadedFiles() {

        $allUploadedFiles = [];

        foreach( $this->stdUploadLanes as $lane ) {

            $allUploadedFiles = array_merge($allUploadedFiles, $lane->uploadedFiles);

        }

        return $allUploadedFiles;

    }

    public function getAllFailedFiles() {

        $allFailedFiles = [];

        // Any any files that weren't attempted to be uploaded
        $allFailedFiles = array_merge($allFailedFiles, $this->failedUnattemptedFiles);

        foreach( $this->stdUploadLanes as $lane ) {

            // Add failed files from each upload lane
            $allFailedFiles = array_merge($allFailedFiles, $lane->failedFiles);

        }

        return $allFailedFiles;

    }

    public function numFilesToUpload() {

        return count($this->filesToUpload);

    }

    public function doUpload() {

        // Create upload lanes
        $numUploadLanes = min($this->numFilesToUpload(), $this->numUploadLanes);

        $this->stdUploadLanes = [];
        $promises = [];

        for( $i = 0; $i < $numUploadLanes; $i++ ) {

            $this->stdUploadLanes[] = $uploadLane = new AsyncUploadLane($this);
            $promises[] = $uploadLane->begin();

        }

        // Wait for all upload lane promises to either resolve or be rejected
        \GuzzleHttp\Promise\Utils::settle($promises)->wait();

        // After all promises have settled, if there are still files to upload, mark them as failed.
        // It means some lanes didn't start correctly.
        if( $this->filesToUpload ) {

            $this->failedUnattemptedFiles = array_slice($this->filesToUpload, 0);

        }

        return $this->getAllFailedFiles() ? false : $this->getAllUploadedFiles();

    }

}

class DirectoryUploader extends ParallelUploader {

    public $directory;
    public $b2Path;

    public $fileGenerator;

    public function __construct($directory, $b2Path, Client $client, $bucketId, $numUploadLanes = null) {

        $this->directory = $directory;
        $this->b2Path = $b2Path . (substr($b2Path, -1) != '/' ? '/' : '');

        $this->fileGenerator = $this->genDirectoryRecursive();

        parent::__construct($client, $bucketId, $numUploadLanes);
        
    }

    public function numFilesToUpload() {

        // Unknown, so just return a large number
        return 999999;

    }

    /* Get all and recursive files list, generator */
    protected function genDirectoryRecursive() {

        $directoryIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory),
            \RecursiveIteratorIterator::CHILD_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        /** @var \DirectoryIterator $fileEntity */
        foreach( $directoryIterator as $fileEntity ) {

            $file = [
                'fileName' => $fileEntity->getFilename(),
                'realPath' => $fileEntity->getRealPath(),
                'mtime'    => $fileEntity->getMTime(),
                'isDir'    => $fileEntity->isDir()
            ];

            // Skip 'dot' folders and directories
            if( $file['fileName'] === '.' || $file['fileName'] === '..' || $file['isDir'] ) {
                continue;
            }

            yield $file;

        }

    }

    public function getNextFile() {

        if( $file = $this->fileGenerator->current() ) {

            $this->fileGenerator->next();

            return [
                'FileName' => $this->b2Path . $file['fileName'],
                'LocalFile' => $file['realPath'],
                'FileLastModified' => $file['mtime'] * 1000,
            ];

        }

    }

}

class AsyncRequestWithRetries {

    public $retryLimit   = 10;
    public $retryWaitSec = 0.5;

    public $debugOutput = false;

    protected $retries = 0;

    public $client;

    public $method;
    public $uri;
    public $options = [];

    public function __construct(Client $client, $method, $uri, $options = []) {

        if( defined('B2_DEBUG_ON') && B2_DEBUG_ON ) $this->debugOutput = true;

        $this->method = $method;
        $this->uri = $uri;
        $this->options = $options;
        $this->options['http_errors'] = true;

        $this->client = $client;
        
    }

    protected function shouldRetry(ResponseInterface $response = null) {

        // Support for 503 "too busy errors". Retry multiple times before failure
        if ((!$response || $response->getStatusCode() === 503) && $this->retries < $this->retryLimit) {

            $this->retries++;

            $this->options['delay'] = $this->retryWaitSec * 1000;

            // Wait 50% longer if it fails again
            $this->retryWaitSec *= 1.5;

            return true;

        }

    }

    public function begin() {

        if( $this->debugOutput ) echo "Requesting " . $this->uri . "\n";

        return $this->client->requestAsync($this->method, $this->uri, $this->options)->then(function (ResponseInterface $response) {

            if( $this->debugOutput ) echo $this->uri . " returned " . $response->getStatusCode() . "\n";

            return $response;

        }, function(\Exception $reason) {

            if( $this->debugOutput ) echo $this->uri . " failed: " . $reason->getMessage() . "\n";

            if( $reason instanceof \GuzzleHttp\Exception\BadResponseException ) {

                $response = $reason->getResponse();

                if( $this->shouldRetry($response) ) {
    
                    return $this->begin();

                } else {

                    throw $reason;

                }

            // If this was a connection exception, test to see if we should retry based on connect timeout rules
            } else if( $reason instanceof \GuzzleHttp\Exception\ConnectException ) {

                if( $this->shouldRetry() ) {

                    return $this->begin();

                } else {

                    throw $reason;

                }

            } else {

                throw $reason;

            }

        });

    }

}

class AsyncUploadFileResult {

    public $originalFile;
    public $b2File;

    public function __construct($originalFile, File $b2File) {

        $this->originalFile = $originalFile;
        $this->b2File = $b2File;
        
    }

}

/** @property AsyncUploadFileResult[] $uploadedFiles */
class AsyncUploadLane {

    public $parallelUploader;

    public $uploadEndpoint;
    public $uploadAuthToken;

    public $uploadedFiles = [];
    public $failedFiles = [];

    public function __construct(ParallelUploader $parallelUploader) {

        $this->parallelUploader = $parallelUploader;
        
    }

    public function begin() {

        $asyncRequest = new AsyncRequestWithRetries($this->parallelUploader->client, 'POST', '/b2_get_upload_url', [
            'json' => [
                'bucketId' => $this->parallelUploader->bucketId,
            ],
        ]);

        return $asyncRequest->begin()->then(function(ResponseInterface $response) {

            $responseObj = json_decode($response->getBody(), true);

            $this->uploadEndpoint = $responseObj['uploadUrl'];
            $this->uploadAuthToken = $responseObj['authorizationToken'];

            return $this->uploadNextFile();

        }, function(\Exception $reason) {

            throw $reason;

        });

    }

    protected function uploadNextFile() {

        if( $nextFile = $this->parallelUploader->getNextFile() ) {

            if( $nextFile['LocalFile'] ) {

                if( !file_exists($nextFile['LocalFile']) ) throw new \Exception("File does not exist: " . $nextFile['LocalFile']);

                if( !isset($nextFile['FileLastModified']) ) $nextFile['FileLastModified'] = filemtime($nextFile['LocalFile']) * 1000;
                $nextFile['Body'] = fopen($nextFile['LocalFile'], 'r');

            }

            // Clean the path if it starts with /.
            if (substr($nextFile['FileName'], 0, 1) === '/') {
                $nextFile['FileName'] = ltrim($nextFile['FileName'], '/');
            }

            if (!isset($nextFile['FileLastModified'])) {
                $nextFile['FileLastModified'] = round(microtime(true) * 1000);
            }

            if (!isset($nextFile['FileContentType'])) {
                $nextFile['FileContentType'] = 'b2/x-auto';
            }

            list($nextFile['hash'], $nextFile['size']) = $this->parallelUploader->client->getFileHashAndSize($nextFile['Body']);

            $asyncRequest = new AsyncRequestWithRetries($this->parallelUploader->client, 'POST', $this->uploadEndpoint, [
                'headers' => [
                    'Authorization' => $this->uploadAuthToken,
                    'Content-Type' => $nextFile['FileContentType'],
                    'Content-Length' => $nextFile['size'],
                    'X-Bz-File-Name' => $nextFile['FileName'],
                    'X-Bz-Content-Sha1' => $nextFile['hash'],
                    'X-Bz-Info-src_last_modified_millis' => $nextFile['FileLastModified'],
                ],
                'body' => $nextFile['Body'],
            ]);

            return $asyncRequest->begin()->then(function(ResponseInterface $response) use ($nextFile) {

                if( is_resource($nextFile['Body']) ) fclose($nextFile['Body']);

                $responseObj = json_decode($response->getBody(), true);
                
                $this->uploadedFiles[] = new AsyncUploadFileResult($nextFile, new File($responseObj));

                return $this->uploadNextFile();
                
            },function(\Exception $reason) use ($nextFile) {
                
                if( is_resource($nextFile['Body']) ) fclose($nextFile['Body']);

                $this->failedFiles[] = $nextFile;

                return $this->uploadNextFile();

            });

        }

    }

}

class ParallelDownloader {

    public $client;

    public $numDownloadLanes = 7;

    public $filesToDownload = [];

    /** @var AsyncDownloadLane[] */
    protected $stdDownloadLanes = [];

    public function __construct(Client $client, $numDownloadLanes = null) {

        $this->client = $client;

        if( $numDownloadLanes ) $this->numDownloadLanes = $numDownloadLanes;
        
    }

    public function addFileToDownload($fileOptions) {

        $this->filesToDownload[] = $fileOptions;

    }

    public function getNextFile() {

        return array_shift($this->filesToDownload);

    }

    /** @return AsyncDownloadFileResult[] */
    public function getAllDownloadedFiles() {

        $allDownloadedFiles = [];

        foreach( $this->stdDownloadLanes as $lane ) {

            $allDownloadedFiles = array_merge($allDownloadedFiles, $lane->downloadedFiles);

        }

        return $allDownloadedFiles;

    }

    public function getAllFailedFiles() {

        $allFailedFiles = [];

        foreach( $this->stdDownloadLanes as $lane ) {

            $allFailedFiles = array_merge($allFailedFiles, $lane->failedFiles);

        }

        return $allFailedFiles;

    }

    public function numFilesToDownload() {

        return count($this->filesToDownload);

    }

    public function doDownload() {

        // Create download lanes
        $numDownloadLanes = min($this->numFilesToDownload(), $this->numDownloadLanes);

        $this->stdDownloadLanes = [];
        $promises = [];

        for( $i = 0; $i < $numDownloadLanes; $i++ ) {

            $this->stdDownloadLanes[] = $downloadLane = new AsyncDownloadLane($this);
            $promises[] = $downloadLane->begin();

        }

        \GuzzleHttp\Promise\Each::of($promises)->then()->wait();

        return $this->getAllFailedFiles() ? false : $this->getAllDownloadedFiles();

    }

}

class AsyncDownloadFileResult {

    public $originalFileOptions;
    public $result;

    public function __construct($originalFileOptions, $result) {

        $this->originalFileOptions = $originalFileOptions;
        $this->result = $result;
        
    }

}

/** @property AsyncDownloadFileResult[] $downloadedFiles */
class AsyncDownloadLane {

    public $parallelDownloader;

    public $downloadedFiles = [];
    public $failedFiles = [];

    public function __construct(ParallelDownloader $parallelDownloader) {

        $this->parallelDownloader = $parallelDownloader;
        
    }

    public function begin() {

        return $this->downloadNextFile();

    }

    protected function downloadNextFile() {

        if( $nextFile = $options = $this->parallelDownloader->getNextFile() ) {

            $client = $this->parallelDownloader->client;
            
            $requestUrl = null;
            $requestOptions = [
                'sink' => isset($options['SaveAs']) ? $options['SaveAs'] : fopen('php://temp', 'w'),
            ];

            if( isset($options['FileId']) ) {

                $requestOptions['query'] = ['fileId' => $options['FileId']];
                $requestUrl = $client->downloadUrl . '/b2api/v1/b2_download_file_by_id';

            } else {

                if( !isset($options['BucketName']) && isset($options['BucketId']) ) {

                    $options['BucketName'] = $client->getBucketNameFromId($options['BucketId']);

                }

                $requestUrl = $client->getB2FileRequestUrl($options['BucketName'], $options['FileName']);

            }

            $asyncRequest = new AsyncRequestWithRetries($client, 'GET', $requestUrl, $requestOptions);

            return $asyncRequest->begin()->then(function(ResponseInterface $response) use ($nextFile, $options) {
                
                $this->downloadedFiles[] = new AsyncDownloadFileResult($nextFile, isset($options['SaveAs']) ? true : $response->getBody());

                return $this->downloadNextFile();
                
            }, function(\Exception $reason) use ($nextFile) {

                $this->failedFiles[] = $nextFile;

                return $this->downloadNextFile();

            });

        }

    }

}