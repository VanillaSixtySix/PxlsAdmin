<?php

namespace pxls\Action;

use pxls\DiscordHook;
use Slim\Views\Twig;
use Psr\Log\LoggerInterface;
use Slim\Http\Request;
use Slim\Http\Response;

final class PrivateAPI
{
    private $view;
    private $logger;
    private $database;
    private $user;

    public function __construct(Twig $view, LoggerInterface $logger, \PDO $database, DiscordHook $discord)
    {
        $this->view = $view;
        $this->logger = $logger;
        $this->database = $database;
        $this->discord = $discord;
        $this->user = new \pxls\User($this->database);
    }

    public function __invoke(Request $request, Response $response, $args)
    {
        $params = explode("/", $request->getAttribute('params'));

        if($request->isMethod("POST")) {
            switch($params[0]) {
                case 'user':
                    return $this->userhandler($request,$response,$args);
                    break;
            }
        } elseif($request->isMethod("GET")) {
            switch($params[0]) {
                case 'activitylog':
                    if(!isset($params[1])) { $scope = null; } else { $scope = $params[1]; }
                    return $response->withStatus(200)->withJson(["data"=>$this->lastActionLog($scope)]);
                    break;
                case 'userinfo':
                    if(!isset($params[1])) { $user = null; } else { $user = $params[1]; }
                    return $response->withStatus(200)->withJson(["data"=>$this->getUserInfo($user)]);
                    break;
                case "lastSignups":
                    return $response->withStatus(200)->withJson(["data"=>$this->lastSignups()]);
                    break;
            }
        }
        return $response;
    }

    protected function getUserInfo($username) {
        return $this->user->getUserByName($username);
    }

    protected function userHandler(Request $request, Response $response, $args) {
        $params = explode("/", $request->getAttribute('params'));
        $postData = $request->getParsedBody();
        switch($params[1]) {
            case 'note':
                switch($params[2]) {
                    case 'add':
                        if(!empty($postData["message"])) {
                            $this->user->addNoteToUser($postData["targetid"], $postData["message"], null);
                            return $response->withJson(["success" => true]);
                        }
                        break;
                    case 'delete':
                        if(!empty($postData["targetid"])) {
                            $this->user->deleteNote($postData["targetid"]);
                            return $response->withJson(["success" => true]);
                        }
                        break;
                    case 'comment':
                        if(!empty($postData["message"]) && $postData["targetid"] != 0) {
                            $this->user->addNoteToUser($postData["targetid"],$postData["message"],$postData["noteid"]);
                            return $response->withJson(["success"=>true]);
                        }
                        break;
                }
        }
        return false;
    }

    protected function lastSignups() {
        $toRet = [];
        $qSignups = $this->database->query("SELECT id,username,signup_time,login,pixel_count,pixel_count_alltime FROM users WHERE 1 ORDER BY signup_time DESC LIMIT 100");
        $qSignups->execute();

        $replacers = [
            "reddit" => "https://reddit.com/u/%%LOGIN",
            "google" => "https://plus.google.com/%%LOGIN",
            "discord" => "javascript:askDiscord('%%LOGIN');",
            "tumblr" => "https://%%LOGIN.tumblr.com/"
        ];

        while ($signup = $qSignups->fetch(\PDO::FETCH_ASSOC)) {
            $parsedTime = $signup["signup_time"];

            $splitPos = strpos($signup["login"], ":");
            $loginData = [
                "service" => substr($signup["login"], 0, $splitPos),
                "ID" => substr($signup["login"], $splitPos+1)
            ];
            if (array_key_exists($loginData["service"], $replacers)) {
                $loginLink = str_replace("%%LOGIN", $loginData["ID"], $replacers[$loginData["service"]]);
                $signup["login"] = '<a href="'.$loginLink.'" target="_blank">'.$loginData["service"].':'.$loginData["ID"].'</a>';
            }

            $username = $signup["username"];
            $signup["username"] = '<a href="userinfo/'.$username.'" target="_blank">'.$username.'</a>';

            $toRet[] = $signup;
        }

        return $toRet;
    }

    protected function lastActionLog($scope=null) {
        $logs = [];
        switch($scope) {
            case 'adminlog':
                $qLogs = $this->database->query("SELECT * FROM admin_log WHERE channel = 'pxlsAdmin' AND message NOT LIKE '%api%' ORDER BY id DESC LIMIT 1000;");
                break;
            case 'canvaslog':
                $qLogs = $this->database->query("SELECT * FROM admin_log WHERE channel = 'pxlsCanvas' ORDER BY id DESC LIMIT 1000;");
                break;
            case 'consolelog':
                $qLogs = $this->database->query("SELECT * FROM admin_log WHERE channel = 'pxlsConsole' ORDER BY id DESC LIMIT 1000;");
                break;
            case 'apilog':
                $qLogs = $this->database->query("SELECT * FROM admin_log WHERE message LIKE '%public api%' ORDER BY id DESC LIMIT 1000;");
                break;
            default:
                $qLogs = $this->database->query("SELECT * FROM admin_log WHERE message NOT LIKE '%public api%' ORDER BY id DESC LIMIT 1000;");
                break;
        }
        $logParser = new \pxls\LogParser();
        $qLogs->execute();
        while($log = $qLogs->fetch(\PDO::FETCH_ASSOC)) {
            if ($log["channel"] == "pxlsConsole") {
                $log["username"] = "Server Console";
            } else {
                $user = $this->user->getUserById($log["userid"]);
                $log["username"] = $user["username"];
            }
            $log["message"] = $logParser->humanLogMessage($logParser->parse($log["message"]),$log["username"]);
            $log["time"] = date("d.m.Y H:i:s",$log["time"]);
            $log["username"] = '<a href="/userinfo/'.$log["username"].'">'.$log["username"].'</a>';
            $logs[] = $log;
        }
        return $logs;
    }

}
