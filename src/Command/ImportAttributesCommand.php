<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Synolia\SyliusAkeneoPlugin\Client\ClientFactory;
use Synolia\SyliusAkeneoPlugin\Factory\AttributePipelineFactory;
use Synolia\SyliusAkeneoPlugin\Payload\Attribute\AttributePayload;

final class ImportAttributesCommand extends Command
{
    protected static $defaultName = 'akeneo:import:attributes';

    /** @var \Synolia\SyliusAkeneoPlugin\Factory\AttributePipelineFactory */
    private $attributePipelineFactory;

    /** @var \Synolia\SyliusAkeneoPlugin\Client\ClientFactory */
    private $clientFactory;

    public function __construct(
        AttributePipelineFactory $attributePipelineFactory,
        ClientFactory $clientFactory,
        string $name = null
    ) {
        parent::__construct($name);
        $this->attributePipelineFactory = $attributePipelineFactory;
        $this->clientFactory = $clientFactory;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ) {
        /** @var \League\Pipeline\Pipeline $categoryPipeline */
        $categoryPipeline = $this->attributePipelineFactory->create();

        /** @var \Synolia\SyliusAkeneoPlugin\Payload\Attribute\AttributePayload $attributePayload */
        $attributePayload = new AttributePayload($this->clientFactory->createFromApiCredentials());
        $categoryPipeline->process($attributePayload);

        return 0;
    }
}
