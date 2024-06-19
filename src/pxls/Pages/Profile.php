<?php

namespace pxls\Action;

use pxls\DiscordHook;
use pxls\Utils;
use Slim\Views\Twig;
use Psr\Log\LoggerInterface;
use Slim\Http\Request;
use Slim\Http\Response;

final class Profile
{
    private $view;
    private $logger;
    private $db;
    private $data = [];

    public function __construct(Twig $view, LoggerInterface $logger, \PDO $database, DiscordHook $discord) {
        global $app;
        $settings = $app->getContainer()->get("settings");

        $this->view = $view;
        $this->logger = $logger;
        $this->db = $database;
        $this->discord = $discord;
        $this->discord->setName($settings["discord"]["name"]." - UserInfo");
    }

    public function __invoke(Request $request, Response $response, $args)
    {
        $this->data["args"] = $args;

        //region UserData
        $user = new \pxls\User($this->db);
        $this->data['userdata'] = $user->getUserById($_SESSION['user_id']);
        //endregion

        $method = null; $identifier = null;
        if (isset($this->data["args"]["username"])) {
            $method = "name";
            $identifier = $this->data["args"]["username"];
        } else if (isset($this->data["args"]["id"])) {
            $method = "id";
            $identifier = $this->data["args"]["id"];
        }

        $userinfo = $this->findUserDetails($method, $identifier);
        if($userinfo) {
            $this->data["userinfo"] = $userinfo;
            krsort($this->data["timeline"]);
            $this->data["pixels"] = $this->findUserPixels($userinfo['id']);
            $this->view->render($response,"userinfo.html.twig",$this->data);
        } else {
            $this->view->render($response,"error/404.html.twig",$this->data);
        }

        return $response;
    }

    protected function findUserPixels($needle) {
        $pixels = $this->db->prepare("SELECT * FROM pixels WHERE who = :userid ORDER BY \"time\" DESC LIMIT 50");
        $pixels->bindParam(":userid", $needle, \PDO::PARAM_INT);
        $pixels->execute();
        return $pixels->fetchAll(\PDO::FETCH_OBJ);
    }

    protected function findUserDetails($method, $needle) {
        $user = new \pxls\User($this->db); $userinfo = null;
        switch ($method) {
            case 'id':
                $userinfo = $user->getUserById($needle);
                break;
            case 'name':
                $userinfo = $user->getUserByName($needle);
                break;
        }

        if(!is_null($userinfo) && $userinfo) {
            $userinfo["logins"] = array_map(function(array $login) {
                return array_merge(['url' => Utils::MakeUserLoginURL($login)], $login);
            }, $userinfo['logins']);
            $userinfo["signup_ip_detail"] = $this->ip2loc($userinfo['signup_ip']);
            $userinfo["last_ip_detail"] = $this->ip2loc($userinfo['last_ip']);
            $userinfo["reports_sent"] = $this->reportsSentByUser($userinfo["id"]);
            $userinfo["reports_recv"] = $this->reportsRecvbyUser($userinfo["id"]);
            $userinfo["notes"] = $user->getUserNotesById($userinfo["id"]);
            $_legacy = $user->getBanlogFromAdminLog($userinfo["id"]);
            $_current = $user->getBanlogFromDB($userinfo['id']);
            $sizes = [
                'legacy' => sizeof($_legacy),
                'current' => sizeof($_current)
            ];
            $userinfo["banlog"] = [
                "legacy" => $_legacy,
                "current" => $_current,
                "count" => $sizes['legacy'] == $sizes['current'] ? $sizes['legacy'] : abs($sizes['legacy'] - $sizes['current'])
            ];
            $userinfo["ip_log"] = $user->getIPLogForUser($userinfo['id']);
            $this->addToTimeline(strtotime($userinfo['signup_time']), "signup", "", 0);
            return $userinfo;
        }
        return false;
    }

    protected function reportsSentByUser($uid) {
        $query = $this->db->prepare("SELECT r.id AS rid, r.reported AS who, r.x, r.y, r.message AS rmessage, r.pixel_id, r.time AS rtime, r.claimed_by, r.closed FROM reports r WHERE reported IS NOT NULL AND r.who = :uid");
        $query->bindParam(":uid", $uid, \PDO::PARAM_INT);
        $query->execute();
        while($row = $query->fetch(\PDO::FETCH_OBJ)) {
            $getUser = new \pxls\User($this->db);
            $reported = $getUser->getUserById($row->who);
            $this->addToTimeline($row->rtime, "report_sent", $row->rmessage, ["id"=>$row->rid, "reported"=>$reported]);
        }
        return $query->rowCount();
    }
    protected function reportsRecvbyUser($uid) {
        $query = $this->db->prepare("SELECT r.id AS rid, r.who AS who, r.x, r.y, r.message AS rmessage, r.pixel_id, r.time AS rtime, r.claimed_by, r.closed FROM reports r WHERE reported IS NOT NULL AND r.reported = :uid;");
        $query->bindParam(":uid", $uid, \PDO::PARAM_INT);
        $query->execute();
        while($row = $query->fetch(\PDO::FETCH_OBJ)) {
            $getUser = new \pxls\User($this->db);
            $reporter = $getUser->getUserById($row->who);
            $this->addToTimeline($row->rtime, "report_recv", $row->rmessage, ["id"=>$row->rid, "reporter"=>$reporter]);
        }
        return $query->rowCount();
    }

    protected function ip2loc($ip) {
        return (array) json_decode(file_get_contents("http://ipinfo.io/".$ip."/json"));
    }

    protected function addToTimeline($time, $scope, $entry, $id = 0) {
        $this->data["timeline"][$time] = ["scope"=>$scope,"entry"=>$entry,"id"=>$id];
    }

}
