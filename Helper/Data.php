<?php
/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageplaza
 * @package     Mageplaza_ImageOptimizer
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\ImageOptimizer\Helper;

use CURLFile;
use Exception;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File as DriverFile;
use Magento\Framework\Filesystem\Io\File as IoFile;
use Magento\Framework\HTTP\Adapter\CurlFactory;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Mageplaza\Core\Helper\AbstractData;
use Mageplaza\ImageOptimizer\Model\Config\Source\Quality;
use Mageplaza\ImageOptimizer\Model\Config\Source\Status;
use Mageplaza\ImageOptimizer\Model\ResourceModel\Image\Collection as ImageOptimizerCollection;
use Mageplaza\ImageOptimizer\Model\ResourceModel\Image\CollectionFactory;
use Zend_Http_Client;
use Zend_Http_Response;

/**
 * Class Data
 * @package Mageplaza\ImageOptimizer\Helper
 */
class Data extends AbstractData
{
    const CONFIG_MODULE_PATH = 'mpimageoptimizer';
    const IMAGETYPE_PNG      = 3;

    /**
     * @var DriverFile
     */
    protected $driverFile;

    /**
     * @var IoFile
     */
    protected $ioFile;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var CurlFactory
     */
    protected $curlFactory;

    /**
     * Data constructor.
     *
     * @param Context $context
     * @param ObjectManagerInterface $objectManager
     * @param StoreManagerInterface $storeManager
     * @param DriverFile $driverFile
     * @param IoFile $ioFile
     * @param CurlFactory $curlFactory
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        Context $context,
        ObjectManagerInterface $objectManager,
        StoreManagerInterface $storeManager,
        DriverFile $driverFile,
        IoFile $ioFile,
        CurlFactory $curlFactory,
        CollectionFactory $collectionFactory
    ) {
        $this->driverFile        = $driverFile;
        $this->ioFile            = $ioFile;
        $this->curlFactory       = $curlFactory;
        $this->collectionFactory = $collectionFactory;

        parent::__construct($context, $objectManager, $storeManager);
    }

    /**
     * @param string $code
     * @param null $storeId
     *
     * @return mixed
     */
    public function getOptimizeOptions($code = '', $storeId = null)
    {
        $code = ($code !== '') ? '/' . $code : '';

        return $this->getModuleConfig('optimize_options' . $code, $storeId);
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getIncludeDirectories($storeId = null)
    {
        try {
            $directories = $this->unserialize($this->getModuleConfig('image_directory/include_directories', $storeId));
        } catch (Exception $e) {
            $directories = [];
        }

        $result = [];
        foreach ($directories as $key => $directory) {
            $result[$key] = $directory['path'];
        }

        return $result;
    }

    /**
     * @param null $storeId
     *
     * @return mixed
     */
    public function getExcludeDirectories($storeId = null)
    {
        try {
            $directories = $this->unserialize($this->getModuleConfig('image_directory/exclude_directories', $storeId));
        } catch (Exception $e) {
            $directories = [];
        }

        $result = [];
        foreach ($directories as $key => $directory) {
            $result[$key] = $directory['path'];
        }

        return $result;
    }

    /**
     * @param string $code
     * @param null $storeId
     *
     * @return mixed
     */
    public function getCronJobConfig($code = '', $storeId = null)
    {
        $code = ($code !== '') ? '/' . $code : '';

        return $this->getModuleConfig('cron_job' . $code, $storeId);
    }

    /**
     * @return mixed
     */
    public function skipTransparentImage()
    {
        return $this->getOptimizeOptions('skip_transparent_img');
    }

    /**
     * @return array
     * @throws FileSystemException
     */
    public function scanFiles()
    {
        $images               = [];
        $includePatterns      = ['#.jpg#', '#.png#', '#.gif#', '#.tif#', '#.bmp#'];
        $excludeDirectories   = $this->getExcludeDirectories();
        $excludeDirectories[] = 'pub/media/catalog/product/cache/';
        $includeDirectories   = $this->getIncludeDirectories();
        if (empty($includeDirectories)) {
            $includeDirectories = ['pub/media/'];
        }
        /** @var ImageOptimizerCollection $collection */
        $collection = $this->collectionFactory->create();
        $pathValues = $collection->getColumnValues('path');

        foreach ($includeDirectories as $directory) {
            if ($this->driverFile->isExists($directory)) {
                $files = $this->driverFile->readDirectoryRecursively($directory);
                foreach ($files as $file) {
                    if (!$this->driverFile->isFile($file)) {
                        continue;
                    }
                    foreach ($excludeDirectories as $excludeDirectory) {
                        if (preg_match('[' . $excludeDirectory . ']', $file)) {
                            continue 2;
                        }
                    }
                    foreach ($includePatterns as $pattern) {
                        if (preg_match($pattern, $file)
                            && !array_key_exists($file, $images)
                            && !in_array($file, $pathValues, true)
                        ) {
                            $imageType = exif_imagetype($file);
                            if ($imageType === self::IMAGETYPE_PNG
                                && $this->skipTransparentImage()
                                && imagecolortransparent(imagecreatefrompng($file)) >= 0
                            ) {
                                $status = Status::SKIPPED;
                            } else {
                                $status = Status::PENDING;
                            }
                            $images[$file] = [
                                'path'        => $file,
                                'status'      => $status,
                                'origin_size' => $this->driverFile->stat($file)['size']
                            ];
                        }
                    }
                }
            }
        }
        $images = array_values($images);

        return $images;
    }

    /**
     * @param $path
     *
     * @return array|mixed
     */
    public function optimizeImage($path)
    {
        $result = [];
        if (!$this->fileExists($path)) {
            $result = [
                'error'      => true,
                'error_long' => __('file does not exist')
            ];

            return $result;
        }

        $curl = $this->curlFactory->create();
        $url  = 'http://api.resmush.it/?qlty=' . $this->getQuality();
        try {
            $params = $this->getParams($path);
            $curl->write(Zend_Http_Client::POST, $url, '1.1', [], $params);
            $resultCurl = $curl->read();
            if (!empty($resultCurl)) {
                $responseBody = Zend_Http_Response::extractBody($resultCurl);
                $result       += Data::jsonDecode($responseBody);
            }
        } catch (Exception $e) {
            $result['error']      = true;
            $result['error_long'] = $e->getMessage();
        }
        $curl->close();

        if (isset($result['dest'])) {
            try {
                $this->saveImage($result['dest'], $path);
            } catch (Exception $e) {
                $result['error']      = true;
                $result['error_long'] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * @param $path
     *
     * @return array
     */
    public function getParams($path)
    {
        $mime   = mime_content_type($path);
        $info   = $this->getPathInfo($path);
        $name   = $info['basename'];
        $output = new CURLFile($path, $mime, $name);
        $params = [
            'files' => $output
        ];

        return $params;
    }

    /**
     * @return int|mixed
     */
    public function getQuality()
    {
        $quality = 100;
        if ($this->getOptimizeOptions('image_quality') === Quality::CUSTOM) {
            $quality = $this->getOptimizeOptions('quality_percent');
        }

        return $quality;
    }

    /**
     * @param $url
     * @param $path
     *
     * @throws Exception
     */
    public function saveImage($url, $path)
    {
        if ($this->getConfigGeneral('backup_image')) {
            $this->processImage($path, true);
        }
        //        if ($this->getOptimizeOptions('force_permission')) {
        //            $this->driverFile->changePermissions($path, (int) $this->getOptimizeOptions('select_permission'));
        //        }

        $this->ioFile->read($url, $path);
    }

    /**
     * @param $path
     *
     * @return bool
     */
    public function fileExists($path)
    {
        return $this->ioFile->fileExists($path);
    }

    /**
     * @param $path
     *
     * @return mixed
     */
    public function getPathInfo($path)
    {
        return $this->ioFile->getPathInfo($path);
    }

    /**
     * @param $path
     * @param bool $backup
     *
     * @throws Exception
     */
    public function processImage($path, $backup = true)
    {
        if ($backup) {
            $pathInfo = $this->getPathInfo($path);
            $folder   = 'var/backup_image/' . $pathInfo['dirname'];
            $this->ioFile->checkAndCreateFolder($folder);
            if (!$this->fileExists('var/backup_image/' . $path)) {
                $this->ioFile->write('var/backup_image/' . $path, $path);
            }
        } else {
            $this->ioFile->write($path, 'var/backup_image/' . $path);
        }
    }
}
