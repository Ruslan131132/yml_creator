<?php

namespace Mtt\AppBundle\Command;

use Mtt\AppBundle\Entity\City;
use Mtt\AppBundle\Entity\Repository\CityRepository;
use Mtt\FrontendBundle\Services\YmlCreator;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateFeedsCommand extends ContainerAwareCommand
{
    /**
     * @var CityRepository
     */
    private $cityRepo;
    /**
     * @var YmlCreator
     */
    private $ymlCreator;

    public function __construct(CityRepository $cityRepo, YmlCreator $ymlCreator)
    {
        $this->cityRepo = $cityRepo;
        $this->ymlCreator = $ymlCreator;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('mtt_app:generate-feeds')
            ->setDescription('Генерация фидов для каждого города')
            ->setHelp('Данная команда предназначена для генерации yml-фидов');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Генерация запущена');

        $city = $this->cityRepo->find(74);

        $progressBar = new ProgressBar($output, 1200000);

        $this->generateFeed($city, $progressBar);

        $progressBar->finish();

        $output->writeln('Фиды успешно сгенерированы!');
    }

    public function generateFeed(City $city, ProgressBar $progressBar)
    {
        $webDir = $this->getContainer()->getParameter('assetic.read_from');

        $url = strpos($city->getUrl(), '.') ? $city->getUrl() : $city->getUrl() . '.met-trans.ru';

        $file = $webDir . '/feeds/' . $city->getId() . '.xml';

        if (!file_exists($file)) {
            touch($file);
        }

        $this->ymlCreator->create([
            'xml_path' => $file,
        ], $this->getConfigData($url), $city, $progressBar);

    }

    private function getConfigData(string $url): array
    {
        $parameters = $this->getContainer()->getParameter('yml_settings');

        return array(
            'name' => $parameters['name'],
            'company' => $parameters['company'],
            'url' => $url
        );
    }

}
