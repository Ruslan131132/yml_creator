<?php

namespace Mtt\FrontendBundle\Controller;

use Mtt\AppBundle\Entity\City;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;


class YmlController extends Controller
{
    /**
     * @Route("/feeds/yml", name="ge_yml")
     * @param City $_city
     * @Template()
     */
    public function showFeedAction($_city)
    {
        $webDir = $this->getParameter('assetic.read_from');
        $xml = file_get_contents($webDir . '/feeds/' . $_city->getId() . '.xml');
        $response = new Response($xml);
        $response->headers->set('Content-Type', 'text/xml');

        return $response;
    }
}
