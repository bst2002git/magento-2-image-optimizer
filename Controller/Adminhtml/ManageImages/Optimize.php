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

namespace Mageplaza\ImageOptimizer\Controller\Adminhtml\ManageImages;

use CURLFile;
use Exception;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\HTTP\Adapter\CurlFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Ui\Component\MassAction\Filter;
use Mageplaza\ImageOptimizer\Controller\Adminhtml\Image;
use Mageplaza\ImageOptimizer\Helper\Data;
use Mageplaza\ImageOptimizer\Model\Config\Source\Quality;
use Mageplaza\ImageOptimizer\Model\Config\Source\Status;
use Mageplaza\ImageOptimizer\Model\ImageFactory;
use Mageplaza\ImageOptimizer\Model\ResourceModel\Image as ResourceImage;
use Mageplaza\ImageOptimizer\Model\ResourceModel\Image\CollectionFactory;
use Psr\Log\LoggerInterface;
use Zend_Http_Client;
use Zend_Http_Response;

/**
 * Class Optimize
 * @package Mageplaza\ImageOptimizer\Controller\Adminhtml\ManageImages
 */
class Optimize extends Image
{
    /**
     * @return ResponseInterface|Redirect|ResultInterface
     */
    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        if (!$this->helperData->isEnabled() && !$this->getRequest()->getParam('isAjax')) {
            $this->messageManager->addErrorMessage(__('The module has been disabled.'));

            return $resultRedirect->setPath('*/*/');
        }

        $imageId = $this->getRequest()->getParam('image_id');
        try {
            /** @var \Mageplaza\ImageOptimizer\Model\Image $model */
            $model = $this->imageFactory->create();
            if ($imageId) {
                $this->resourceModel->load($model, $imageId);
                if ($imageId !== $model->getId()) {
                    if ($this->getRequest()->getParam('isAjax')) {
                        return $this->getResponse()->representJson(Data::jsonEncode(['status' => Status::ERROR]));
                    }
                    $this->messageManager->addErrorMessage(__('The wrong image is specified.'));

                    return $resultRedirect->setPath('*/*/');
                }

                if ($model->getData('status') === Status::SUCCESS) {
                    if ($this->getRequest()->getParam('isAjax')) {
                        return $this->getResponse()->representJson(Data::jsonEncode([
                            'status' => 'optimized',
                            'path'   => $model->getData('path')
                        ]));
                    }
                    $this->messageManager->addErrorMessage(__('The image(s) had already been optimized previously'));

                    return $resultRedirect->setPath('*/*/');
                }

                if ($model->getData('status') === Status::SKIPPED) {
                    if ($this->getRequest()->getParam('isAjax')) {
                        return $this->getResponse()->representJson(Data::jsonEncode($model->getData()));
                    }
                    $this->messageManager->addErrorMessage(__('The image(s) are skipped.'));

                    return $resultRedirect->setPath('*/*/');
                }
            }
            $result = $this->helperData->optimizeImage($model->getData('path'));
            $data   = [
                'optimize_size' => isset($result['error']) ? '' : $result['dest_size'],
                'percent'       => isset($result['error']) ? '' : $result['percent'],
                'status'        => isset($result['error']) ? Status::ERROR : Status::SUCCESS,
                'message'       => isset($result['error']) ? $result['error_long'] : ''
            ];
            $model->addData($data);
            $this->resourceModel->save($model);
            if ($this->getRequest()->getParam('isAjax')) {
                return $this->getResponse()->representJson(Data::jsonEncode($model->getData()));
            }
            if (isset($result['error'])) {
                $this->messageManager->addErrorMessage(__($result['error_long']));
            } else {
                $this->messageManager->addSuccessMessage(__('Image(s) have been optimized successfully.'));
            }
        } catch (Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->logger->critical($e->getMessage());
        }

        return $resultRedirect->setPath('*/*/');
    }
}
