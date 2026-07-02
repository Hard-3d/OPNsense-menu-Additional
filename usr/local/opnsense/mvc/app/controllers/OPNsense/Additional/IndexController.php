<?php

namespace OPNsense\Additional;

use OPNsense\Base\IndexController as BaseIndexController;

class IndexController extends BaseIndexController
{
    public function ethnameAction()
    {
        $this->view->pick('OPNsense/Additional/ethname');
    }

    public function geoipupdateAction()
    {
        $this->view->pick('OPNsense/Additional/geoipupdate');
    }

    public function checkstatusAction()
    {
        $this->view->pick('OPNsense/Additional/checkstatus');
    }

    public function wireguardpeersAction()
    {
        $this->view->pick('OPNsense/Additional/wireguardpeers');
    }

    public function checkwanAction()
    {
        $this->view->pick('OPNsense/Additional/checkwan');
    }

    public function udp2rawAction()
    {
        $this->view->pick('OPNsense/Additional/udp2raw');
    }

    public function schedulerAction()
    {
        $this->view->pick('OPNsense/Additional/scheduler');
    }

    public function updaterAction()
    {
        $this->view->pick('OPNsense/Additional/updater');
    }

    public function centralAction()
    {
        $this->view->pick('OPNsense/Additional/central');
    }
}
