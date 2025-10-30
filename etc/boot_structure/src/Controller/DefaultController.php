<?php

namespace App\Controller;

use OpenDxp\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Response;

class DefaultController extends FrontendController
{
    public function defaultAction(Request $request): Response
    {
        return $this->render('default/default.html.twig');
    }
}
