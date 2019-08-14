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

namespace Mageplaza\ImageOptimizer\Console\Command;

use Exception;
use Mageplaza\ImageOptimizer\Helper\Data;
use Mageplaza\ImageOptimizer\Model\ResourceModel\Image as ResourceImage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Scan
 * @package Mageplaza\ImageOptimizer\Console\Command
 */
class Scan extends Command
{
    /**
     * @var Data
     */
    protected $helperData;

    /**
     * @var ResourceImage
     */
    protected $resourceModel;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Scan constructor.
     *
     * @param Data $helperData
     * @param ResourceImage $resourceModel
     * @param LoggerInterface $logger
     * @param string|null $name
     */
    public function __construct(
        Data $helperData,
        ResourceImage $resourceModel,
        LoggerInterface $logger,
        string $name = null
    ) {
        parent::__construct($name);

        $this->helperData    = $helperData;
        $this->resourceModel = $resourceModel;
        $this->logger        = $logger;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('mpimageoptimizer:scan');
        $this->setDescription('Image Optimizer console command.');

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return $this|int|null
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->helperData->isEnabled()) {
            return $this;
        }

        try {
            $data = $this->helperData->scanFiles();
            if (empty($data)) {
                return $this;
            }
            $this->resourceModel->insertImagesData($data);
            $output->writeln(__('<info>Successful data scanning.</info>'));
        } catch (Exception  $e) {
            $output->writeln(__('<error>Something went wrong while scan images. Please review the error log.</error>'));
            $this->logger->critical($e->getMessage());
        }

        return $this;
    }
}
