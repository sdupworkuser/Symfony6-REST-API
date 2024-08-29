<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateSiteMapCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('restapi:generate:sitemap')
            ->setDescription('Generate sitemap');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        ini_set('max_execution_time', 0); // Let it rip!

        $container = $this->getContainer();
        if ($container->getParameter('crons_enabled') == "true") {
            $container->get('sitemap_service')->generateSiteMap();

            $container->get('aws_s3_service')->putResource(
                $container->getParameter('aws_public_bucket'),
                'sitemap/sitemap.xml',
                'web/sitemap.xml'
            );
        }
    }
}
