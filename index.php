<?php


$GLOBALS['version'] = 0.4;

require 'config.php';

include 'app/main/plugins.class.php';
plugins::start('plugins/');

require 'vendor/autoload.php';
$klein = new \Klein\Klein;

$klein->respond('*', function ($request, $response, $service) {

    // Logging System
    ini_set("error_log", realpath('logs') . "/" . date('mdy') . ".log");

    // Set Socket Timeout
    ini_set('default_socket_timeout', 5);

    // CRON and Steam Auth Check
    if ($request->uri != "/api/cron") {
        session_start();
        require (getcwd() . '/steamauth/steamauth.php');
        require (getcwd() . '/app/main/q3query.class.php');
    }

    // MySQL Injection Prevention
    function escapestring($value)
    {
        $conn = new mysqli($GLOBALS['mysql_host'], $GLOBALS['mysql_user'], $GLOBALS['mysql_pass'], $GLOBALS['mysql_db']);
        if ($conn->connect_errno) {
            die('Could not connect: ' . $conn->connect_error);
        }
        return strip_tags(mysqli_real_escape_string($conn, $value));
    }

    // Insert into Database
    function dbquery($sql, $returnresult = true)
    {
        $conn = new mysqli($GLOBALS['mysql_host'], $GLOBALS['mysql_user'], $GLOBALS['mysql_pass'], $GLOBALS['mysql_db']);
        if ($conn->connect_errno) {
            error_log('MySQL could not connect: ' . $conn->connect_error);
            return $conn->connect_error;
        }

        $return = array();

        $result = mysqli_query($conn, $sql);
        if ($returnresult) {
            if (mysqli_num_rows($result) != 0) {
                while ($r = $result->fetch_assoc()) {
                    array_push($return, $r);
                }
            } else {
                $return = array();
            }

        } else {
            $return = array();
        }

        return $return;
    }

    // Check HTTPS and Force Value
    if((strpos($GLOBALS['domainname'], 'https://') !== false)) {
        if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] == "off") {
            header("Location: " . $GLOBALS['domainname']);
        }
    }

    function userCommunity($steamid) {
        return dbquery('SELECT * FROM users WHERE steamid="' . escapestring($steamid) . '"')[0]['community'];
    }

    function siteConfig($option, $community = NULL) { 
        if($community == NULL) {
            $community = userCommunity($_SESSION['steamid']);
        }
        return dbquery('SELECT * FROM config WHERE community="' . $community . '"')[0][$option];
    }

    $GLOBALS['serveractions'] = json_decode(json_encode(unserialize(dbquery('SELECT * FROM config WHERE community="' . userCommunity($_SESSION['steamid']) . '"', true)[0]['serveractions'])), true);
    $GLOBALS['permissions'] = json_decode(json_encode(unserialize(dbquery('SELECT * FROM config WHERE community="' . userCommunity($_SESSION['steamid']) . '"', true)[0]['permissions'])), true);

    function checkPlugin($plugin) {
        return dbquery('SELECT * FROM config WHERE community="' . escapestring(userCommunity($_SESSION['steamid'])) . '"')[0]['plugin_' . $plugin];
    }
    
    // Check FiveM Server Status
    function checkOnline($site)
    {
        $curlInit = curl_init(strtok($site, ':'));
        curl_setopt($curlInit, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($curlInit, CURLOPT_PORT, str_replace(':', '', substr($site, strpos($site, ':'))));
        curl_setopt($curlInit, CURLOPT_HEADER, true);
        curl_setopt($curlInit, CURLOPT_NOBODY, true);
        curl_setopt($curlInit, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($curlInit);
        curl_close($curlInit);

        if ($response) {return true;} else {return false;}

    }

    // Get Dashboard Statistics
    function getStats($community = null)
    {
        $warns = 0;
        $kicks = 0;
        $bans = 0;
        $commends = 0;
        $playtime = 0;

        if($community == null) {
            $community = userCommunity($_SESSION['steamid']);
        }

        foreach (dbquery('SELECT * FROM warnings WHERE community="' . $community . '"') as $warn) {$warns++;}
        foreach (dbquery('SELECT * FROM kicks WHERE community="' . $community . '"') as $kick) {$kicks++;}
        foreach (dbquery('SELECT * FROM bans WHERE community="' . $community . '"') as $ban) {$bans++;}
        foreach (dbquery('SELECT * FROM commend WHERE community="' . $community . '"') as $commend) {$commends++;}
        foreach (dbquery('SELECT * FROM players WHERE community="' . $community . '"') as $playedtime) {
            $playtime = $playtime + $playedtime['playtime'];
        }

        $stats = array(
            'warns' => $warns,
            'kicks' => $kicks,
            'bans' => $bans,
            'playtime' => $playtime,
            'commends' => $commends,
        );

        return $stats;
    }

    // Get FiveM Server Information
    function serverInfo($conn)
    {
        $json = @file_get_contents('http://' . $conn . '/players.json');
        $data = json_decode($json);

        $players = 0;
        foreach ($data as $player) {
            $players++;
        }

        sort($data);

        $info = array(
            'playercount' => $players,
            'players' => $data,
        );

        return $info;
    }

    // Return Server Details
    function serverDetails($conn) {
        $json = @file_get_contents('http://' . $conn . '/info.json');
        $data = json_decode($json);

        return $data;
    }

    // Get SteamID Rank in Panel
    function getRank($input)
    {
        return dbquery('SELECT * FROM users WHERE steamid="' . escapestring($input) . '"')[0]['rank'];
    }

    // Get SteamID Rank in Panel
    function isBeta($input)
    {
        return dbquery('SELECT * FROM users WHERE steamid="' . escapestring($input) . '"')[0]['beta'];
    }

    // Get if Support Staff in Panel
    function isStaff($input)
    {
        return dbquery('SELECT * FROM users WHERE steamid="' . escapestring($input) . '"')[0]['staff'];
    }

    // Get Name from SteamID
    function getName($input)
    {
        return dbquery('SELECT * FROM users WHERE steamid="' . escapestring($input) . '"')[0]['name'];
    }

    // Checks SteamID Permission against Permissions Array
    function hasPermission($steam, $perm, $community = null)
    {
        $rank = getRank($steam);
        if($community == null) {
            if (!$GLOBALS['permissions'][$rank] == null) {
                return in_array($perm, $GLOBALS['permissions'][$rank]);
            } else {
                return false;
            }
        } else {
            $permissions = json_decode(json_encode(unserialize(dbquery('SELECT * FROM config WHERE community="' . escapestring($community) . '"', true)[0]['permissions'])), true);
            if (!$permissions[$rank] == null) {
                return in_array($perm, $permissions[$rank]);
            } else {
                return false;
            }
        }
    }

    // Checks if Ran From Cron Job
    function isCron()
    {
        return true;
    }

    // Remove Player from FiveM Servers
    function removeFromSession($license, $reason, $server = null)
    {
        if ($server != null) {
            if (checkOnline($server['connection']) == true) {
                $con = new q3query(strtok($server['connection'], ':'), str_replace(':', '', substr($server['connection'], strpos($server['connection'], ':'))), $success);
                foreach (json_decode(@file_get_contents('http://' . $server['connection'] . '/players.json')) as $player) {
                    if ($player->identifiers[1] == $license) {
                        $userid = $player->id;
                        $con->setRconpassword($server['rcon']);
                        $con->rcon("staff_kick $userid $reason");
                    }
                }
            }
        } else {
            foreach (dbquery('SELECT * FROM servers WHERE community="' . userCommunity($_SESSION['steamid']) . '"') as $server) {
                if (checkOnline($server['connection']) == true) {
                    $con = new q3query(strtok($server['connection'], ':'), str_replace(':', '', substr($server['connection'], strpos($server['connection'], ':'))), $success);
                    foreach (json_decode(@file_get_contents('http://' . $server['connection'] . '/players.json')) as $player) {
                        if ($player->identifiers[1] == $license) {
                            $userid = $player->id;
                            $con->setRconpassword($server['rcon']);
                            $con->rcon("staff_kick $userid $reason");
                        }
                    }
                }
            }
        }
    }

    // Send Messages to FiveM Servers
    function sendMessage($message, $server = null)
    {
        if ($server != null) {
            if (checkOnline($server['connection']) == true) {
                $con = new q3query(strtok($server['connection'], ':'), str_replace(':', '', substr($server['connection'], strpos($server['connection'], ':'))), $success);
                $con->setRconpassword($server['rcon']);
                $con->rcon("staff_sayall " . $message);
            }
        } else {
            foreach (dbquery('SELECT * FROM servers WHERE community="' . userCommunity($_SESSION['steamid']) . '"') as $server) {
                if (checkOnline($server['connection']) == true) {
                    $con = new q3query(strtok($server['connection'], ':'), str_replace(':', '', substr($server['connection'], strpos($server['connection'], ':'))), $success);
                    $con->setRconpassword($server['rcon']);
                    $con->rcon("staff_sayall " . $message);
                }
            }
        }
    }

    // Send Message to Player on FiveM Server
    function sendPlayerMessage($license, $reason, $server = null)
    {
        if ($server != null) {
            if (checkOnline($server['connection']) == true) {
                $con = new q3query(strtok($server['connection'], ':'), str_replace(':', '', substr($server['connection'], strpos($server['connection'], ':'))), $success);
                foreach (json_decode(@file_get_contents('http://' . $server['connection'] . '/players.json')) as $player) {
                    if ($player->identifiers[1] == $license) {
                        $userid = $player->id;
                        $con->setRconpassword($server['rcon']);
                        $con->rcon("staff_tell $userid $reason");
                    }
                }
            }
        } else {
            foreach (dbquery('SELECT * FROM servers WHERE community="' . userCommunity($_SESSION['steamid']) . '"') as $server) {
                if (checkOnline($server['connection']) == true) {
                    $con = new q3query(strtok($server['connection'], ':'), str_replace(':', '', substr($server['connection'], strpos($server['connection'], ':'))), $success);
                    foreach (json_decode(@file_get_contents('http://' . $server['connection'] . '/players.json')) as $player) {
                        if ($player->identifiers[1] == $license) {
                            $userid = $player->id;
                            $con->setRconpassword($server['rcon']);
                            $con->rcon("staff_tell $userid $reason");
                        }
                    }
                }
            }
        }
    }

    // Get Players Trustscore
    function trustScore($license, $community = NULL)
    {

        if($community == NULL) {
            $community = userCommunity($_SESSION['steamid']);
        }

        $license = escapestring($license);
        $ts = siteConfig('trustscore', $community);

        $info = dbquery('SELECT * FROM players WHERE license="' . $license . '" AND community="' . escapestring($community) . '"');

        if (empty($info)) {return $ts;}
        $ts = $ts + floor($info[0]['playtime'] / (siteConfig('tstime', $community) * 60));

        if ($ts > 100) {
            $ts = 100;
        }

        foreach (dbquery('SELECT * FROM warnings WHERE license="' . $license . '" AND community="' . $community . '"') as $warn) {$ts = $ts - siteConfig('tswarn', $community);}
        foreach (dbquery('SELECT * FROM kicks WHERE license="' . $license . '" AND community="' . $community . '"') as $kick) {$ts = $ts - siteConfig('tskick', $community);}
        foreach (dbquery('SELECT * FROM bans WHERE identifier="' . $license . '" AND community="' . $community . '"') as $ban) {$ts = $ts - siteConfig('tsban', $community);}
        foreach (dbquery('SELECT * FROM commend WHERE license="' . $license . '" AND community="' . $community . '"') as $commend) {$ts = $ts + siteConfig('tscommend', $community);}

        if ($ts > 100) {
            $ts = 100;
        }

        return $ts;
    }

    // Seconds to Human Readable
    function secsToStr($duration)
    {
        if ($duration < 60) {
            $duration = 60;
        }
        $periods = array(
            'Day' => 86400,
            'Hour' => 3600,
            'Minute' => 60,
            'Second' => 1,
        );

        $parts = array();

        foreach ($periods as $name => $dur) {
            $div = floor($duration / $dur);

            if ($div == 0) {
                continue;
            } else
            if ($div == 1) {
                $parts[] = $div . " " . $name;
            } else {
                $parts[] = $div . " " . $name . "s";
            }

            $duration %= $dur;
        }

        $last = array_pop($parts);

        if (empty($parts)) {
            return $last;
        } else {
            return join(', ', $parts) . " and " . $last;
        }

    }

    // Seconds to Human Readable
    function secsToStrRound($duration)
    {
        $duration = $duration + 2;
        $periods = array(
            'Day' => 86400,
            'Hour' => 3600,
            'Minute' => 60,
        );

        $parts = array();

        foreach ($periods as $name => $dur) {
            $div = floor($duration / $dur);

            if ($div == 0) {
                continue;
            } else
            if ($div == 1) {
                $parts[] = $div . " " . $name;
            } else {
                $parts[] = $div . " " . $name . "s";
            }

            $duration %= $dur;
        }

        $last = array_pop($parts);

        if (empty($parts)) {
            return $last;
        } else {
            return join(', ', $parts) . " and " . $last;
        }

    }

    // Send Message to Discord
    function discordMessage($title, $message, $community = null)
    {
        if($community == null) {
            $webhook = siteConfig('discord_webhook');
            if (empty($webhook) || $webhook == null) {
                return;
            }
        } else {
            $webhook = dbquery('SELECT * FROM config WHERE community="' . escapestring($community) . '"', true)[0]['discord_webhook'];
        }

        if($webhook == "" || $webhook == null) {
            exit();
        }
        
        $discordMessage = '
			{
				"username": "' . siteConfig('community_name') . ' Bot",
				"avatar_url": "https://pbs.twimg.com/profile_images/847824193899167744/J1Teh4Di_400x400.jpg",
				"content": "",
				"embeds": [{
					"title": "' . $title . '",
					"description": "' . $message . '",
					"type": "link",
					"timestamp": "' . date('c') . '"
				}]
			}
		';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $webhook);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(json_decode($discordMessage)));
        curl_exec($curl);
        curl_close($curl);
    }

    // Send Message to Staff Discord
    function staffDiscordMessage($title, $message)
    {
        $webhook = "https://discordapp.com/api/webhooks/545868296558477332/yyqdyFsMo4W0f7LX9oKjTDF31cr6g1Ezkg6ETxiyJYLMpDHfQ4ITJvwndtClIcRdaEDS";
        if (empty($webhook) || $webhook == null) {
            return;
        }

        $discordMessage = '
			{
				"username": "FiveM Admin Panel Bot",
				"avatar_url": "https://pbs.twimg.com/profile_images/847824193899167744/J1Teh4Di_400x400.jpg",
				"content": "",
				"embeds": [{
					"title": "' . $title . '",
					"description": "' . $message . '",
					"type": "link",
					"timestamp": "' . date('c') . '"
				}]
			}
		';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $webhook);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(json_decode($discordMessage)));
        curl_exec($curl);
        curl_close($curl);
    }
    // Decimal to Hex
    function dec2hex($number)
    {
        $hexvalues = array('0', '1', '2', '3', '4', '5', '6', '7',
            '8', '9', 'A', 'B', 'C', 'D', 'E', 'F');
        $hexval = '';
        while ($number != '0') {
            $hexval = $hexvalues[bcmod($number, '16')] . $hexval;
            $number = bcdiv($number, '16', 0);
        }
        return $hexval;
    }

    // Hex to Decimal
    function hex2dec($number)
    {
        $decvalues = array('0' => '0', '1' => '1', '2' => '2',
            '3' => '3', '4' => '4', '5' => '5',
            '6' => '6', '7' => '7', '8' => '8',
            '9' => '9', 'A' => '10', 'B' => '11',
            'C' => '12', 'D' => '13', 'E' => '14',
            'F' => '15');
        $decval = '0';
        $number = strrev($number);
        for ($i = 0; $i < strlen($number); $i++) {
            $decval = bcadd(bcmul(bcpow('16', $i, 0), $decvalues[$number{$i}]), $decval);
        }
        return $decval;
    }

    // Steam Auth Check and Panel Permission Check
    if (!isset($_SESSION['steamid'])) {
        if (strpos($_SERVER['REQUEST_URI'], '/api/') === false) {
            steamLogin();
            exit();
        }
    } else {
        include (getcwd() . '/steamauth/userInfo.php');
        $user = dbquery('SELECT * FROM users WHERE steamid="' . $_SESSION['steamid'] . '"');

        if (strpos($_SERVER['REQUEST_URI'], '/api/') === false) {
            if ($user[0]['rank'] == "user") {
                // No Access
                //echo "<center><h2>" . siteConfig('community_name') . " Staff Panel</h2><p>You currently do not have access to the staff panel. Please contact the administration team.</p></center>";
                $service->render('app/pages/community.php', array('community' => 'FiveM Admin Panel', 'title' => 'Create/Join Community'));
                exit;
            }
        }
    }

    function createUniqueID($lenght = 13) {
        if (function_exists("random_bytes")) {
            $bytes = random_bytes(ceil($lenght / 2));
        } elseif (function_exists("openssl_random_pseudo_bytes")) {
            $bytes = openssl_random_pseudo_bytes(ceil($lenght / 2));
        } else {
            throw new Exception("no cryptographically secure random function available");
        }
        return strtoupper(substr(bin2hex($bytes), 0, $lenght));
    }

    // Extensive Debugging Check
    if (siteConfig('debug') == "true") {
        error_log(print_r(debug_backtrace(), true));
    }
    
});

$klein->respond('GET', '/', function ($request, $response, $service) {
    $servers = dbquery('SELECT connection FROM servers WHERE community="' . userCommunity($_SESSION['steamid']) . '"');
    $players = 0;
    foreach ($servers as $server) {
        if (checkOnline($server['connection'])) {
            $players = $players + serverInfo($server['connection'])['playercount'];
        }
    }
    $service->render('app/pages/dashboard.php', array('community' => siteConfig('community_name'), 'title' => 'Dashboard', 'players' => $players, 'stats' => getStats()));
});

// Leave Community URL
$klein->respond('GET', '/leave', function ($request, $response, $service) {
    if(empty(dbquery('SELECT * FROM communities WHERE owner="' . $_SESSION['steamid'] . '" AND uniqueid="' . userCommunity($_SESSION['steamid']) . '"')[0])) {
        dbquery('UPDATE users SET rank="user", community="" WHERE steamid="' . $_SESSION['steamid'] . '"', false);
        header("Location: " . $GLOBALS['domainname']);
    } else {
        header('Content-Type: application/json');
        echo json_encode(array('error'=>'You can\'t leave a community you own. Please transfer ownership or delete the community.', 'note'=>'Better looking error page coming soon...'));
        exit();
    }
});

// TEMP
$klein->respond('GET', '/temp', function ($request, $response, $service) {
    exec('/opt/cpanel/ea-php70/root/usr/bin/lsphp worker.php test=testing > execoutput.txt 2>&1 &');
});

// Temp Download Resource
$klein->respond('GET', '/download', function ($request, $response, $service) {
    $downloadfile = "resource.zip";

    $downloadfile_name = basename($downloadfile);

    header("Content-Type: application/zip");
    header("Content-Disposition: attachment; filename=$downloadfile_name");
    header("Content-Length: " . filesize($downloadfile));

    readfile($downloadfile);
    exit;
});

$klein->respond('GET', '/data/[players|bans|warns|kicks|commends:action]', function ($request, $response, $service) {
    switch ($request->action) {
        case "players":
            $service->render('app/pages/data/players.php', array('community' => siteConfig('community_name'), 'title' => 'Players List'));
            break;
        case "bans":
            $service->render('app/pages/data/bans.php', array('community' => siteConfig('community_name'), 'title' => 'Bans List'));
            break;
        case "warns":
            $service->render('app/pages/data/warns.php', array('community' => siteConfig('community_name'), 'title' => 'Warnings List'));
            break;
        case "kicks":
            $service->render('app/pages/data/kicks.php', array('community' => siteConfig('community_name'), 'title' => 'Kicks List'));
            break;
        case "commends":
            $service->render('app/pages/data/commends.php', array('community' => siteConfig('community_name'), 'title' => 'Commendations List'));
            break;
    }
});

$klein->respond('GET', '/support/[downloads|tickets|admin:action]', function ($request, $response, $service) {
    switch ($request->action) {
        case "downloads":
            $service->render('app/pages/support/downloads.php', array('community' => "FiveM Admin Panel", 'title' => 'Downloads'));
            break;
        case "tickets":
            $service->render('app/pages/support/tickets.php', array('community' => "FiveM Admin Panel", 'title' => 'Support Tickets'));
            break;
        case "admin":
            if(isStaff($_SESSION['steamid'])) {
                $service->render('app/pages/support/admin/tickets.php', array('community' => "FiveM Admin Panel", 'title' => 'Admin Support Tickets'));
            } else {
                throw Klein\Exceptions\HttpException::createFromCode(404);
            }
            break;
    }
});

$klein->respond('GET', '/support/admin/ticket/[:id]', function ($request, $response, $service) {
    if(!isStaff($_SESSION['steamid'])) {
        throw Klein\Exceptions\HttpException::createFromCode(404);
        exit();
    }
    if(!empty($request->id)) {
        $ticketinfo = dbquery('SELECT * FROM support_tickets WHERE ticketid="' . escapestring($request->id) . '"')[0];
        if(!empty($ticketinfo)) {
            $service->render('app/pages/support/admin/ticket.php', array('community' => "FiveM Admin Panel", 'title' => 'Admin View Ticket', 'ticketinfo' => $ticketinfo));
        } else {
            throw Klein\Exceptions\HttpException::createFromCode(404);
        }
    } else {
        throw Klein\Exceptions\HttpException::createFromCode(404);
    }
    
});

$klein->respond('GET', '/support/ticket/[:id]', function ($request, $response, $service) {
    if(!empty($request->id)) {
        $ticketinfo = dbquery('SELECT * FROM support_tickets WHERE ticketid="' . escapestring($request->id) . '" AND steamid="' . escapestring($_SESSION['steamid']) . '"')[0];
        if(!empty($ticketinfo)) {
            $service->render('app/pages/support/ticket.php', array('community' => "FiveM Admin Panel", 'title' => 'View Ticket', 'ticketinfo' => $ticketinfo));
        } else {
            throw Klein\Exceptions\HttpException::createFromCode(404);
        }
    } else {
        throw Klein\Exceptions\HttpException::createFromCode(404);
    }
    
});

$klein->respond('POST', '/api/support/[addcomment|addticket:action]', function ($request, $response, $service) {
    header('Content-Type: application/json');
    if (isset($_SESSION['steamid'])) {
        if (getRank($_SESSION['steamid']) != "user") {
            switch ($request->action) {
                case "addcomment":
                    if ($request->param('message') == null || $request->param('ticketid') == null) {
                        echo json_encode(array("response" => "400", "message" => "Invalid API Endpoint"));
                    } else {
                        if ($_SESSION['steamid'] == dbquery('SELECT * FROM support_tickets WHERE ticketid="' . escapestring($request->param('ticketid')) . '"')[0]['steamid'] || isStaff($_SESSION['steamid'])) {
                            dbquery('INSERT INTO support_comments (message, ticketid, commentid, steamid, time) VALUES ("' . escapestring($request->param('message')) . '", "' . escapestring($request->param('ticketid')) . '", "' . createUniqueID(12) . '", "' . $_SESSION['steamid'] . '", "' . time() . '")', false);
                            staffDiscordMessage('New Comment', '**Ticket ID: **' . $request->param('ticketid') . '\n**Message: **' . $request->param('message') . '\n**Author: **' . $_SESSION['steamid']);                            
                            echo json_encode(array('success' => true, 'reload' => true));
                        }
                    }
                    break;
                case "addticket":
                    if ($request->param('message') == null || $request->param('title') == null) {
                        echo json_encode(array("response" => "400", "message" => "Invalid API Endpoint"));
                    } else {
                        $ticketid = createUniqueID(8);
                        dbquery('INSERT INTO support_tickets (title, message, ticketid, steamid, time) VALUES (
                            "' . escapestring($request->param('title')) . '",
                            "' . escapestring($request->param('message')) . '",
                            "' . $ticketid . '",
                            "' . $_SESSION['steamid'] . '",
                            "' . time() . '"
                            )', false);
                        staffDiscordMessage('New Ticket #' . $ticketid, '**Title: **' . $request->param('title') . '\n**Message: **' . $request->param('message') . '\n**Author: **' . $_SESSION['steamid']);
                        echo json_encode(array('success' => true, 'goURL' => '/support/ticket/' . $ticketid));
                    }
                    break;
            }
        } else {
            echo json_encode(array("response" => "403", "message" => "User rank does not have access to POST API."));
        }
    } else {
        echo json_encode(array("response" => "401", "message" => "Unauthenticated API request."));
    }
});

$klein->respond('GET', '/server/[:connection]', function ($request, $response, $service) {
    $connection = escapestring($request->connection);
    if (checkOnline($connection)) {
        $server = dbquery('SELECT * FROM servers WHERE connection="' . $connection . '" AND community="' . userCommunity($_SESSION['steamid']) . '"');
        if (!empty($server)) {
            $service->render('app/pages/server.php', array('community' => siteConfig('community_name'), 'title' => 'Server', 'server' => $server[0], 'info' => serverInfo($connection)));
        } else {
            throw Klein\Exceptions\HttpException::createFromCode(404);
        }
    } else {
        $service->render('app/pages/offline.php', array('community' => siteConfig('community_name'), 'title' => 'Server Offline'));
    }
});

$klein->respond('GET', '/recent', function ($request, $response, $service) {
    $service->render('app/pages/recentplayers.php', array('community' => siteConfig('community_name'), 'title' => 'Recent Players'));
});

$klein->respond('GET', '/user/[:license]', function ($request, $response, $service) { 
    if(!isBeta($_SESSION['steamid'])) {
        $service->render('app/pages/user.php', array('community' => siteConfig('community_name'), 'title' => 'Server', 'userinfo' => dbquery('SELECT * FROM players WHERE license="' . escapestring($request->license) . '" AND community="' . userCommunity($_SESSION['steamid']) . '"')[0]));
    } else {
        $service->render('app/pages/beta/user.php', array('community' => siteConfig('community_name'), 'title' => 'Server', 'userinfo' => dbquery('SELECT * FROM players WHERE license="' . escapestring($request->license) . '" AND community="' . userCommunity($_SESSION['steamid']) . '"')[0]));
    }
});

$klein->respond('GET', '/api', function ($request, $response, $service) {
    header('Content-Type: application/json');
    echo json_encode(array("response" => "400", "message" => "Invalid API Endpoint"));
});

$klein->respond('GET', '/admin/[staff|servers|panel:action]', function ($request, $response, $service) {
    switch ($request->action) {
        case "staff":
            if (hasPermission($_SESSION['steamid'], "editstaff")) {
                $service->render('app/pages/admin/staff.php', array('community' => siteConfig('community_name'), 'title' => 'Staff'));
            } else {
                throw Klein\Exceptions\HttpException::createFromCode(404);
            }
            break;
        case "servers":
            if (hasPermission($_SESSION['steamid'], "editservers")) {
                $service->render('app/pages/admin/servers.php', array('community' => siteConfig('community_name'), 'title' => 'Servers'));
            } else {
                throw Klein\Exceptions\HttpException::createFromCode(404);
            }
            break;
        case "panel":
            if (hasPermission($_SESSION['steamid'], "editpanel")) {
                $service->render('app/pages/admin/settings.php', array('community' => siteConfig('community_name'), 'title' => 'Panel Settings'));
            } else {
                throw Klein\Exceptions\HttpException::createFromCode(404);
            }
            break;
    }
});

$klein->respond('GET', '/admin/profile/[:steamid]', function ($request, $response, $service) {
    if (escapestring($request->steamid) == $_SESSION['steamid'] || hasPermission($_SESSION['steamid'], 'editstaff')) {
        $service->render('app/pages/admin/staffinfo.php', array('community' => siteConfig('community_name'), 'title' => 'Staff Information', 'userinfo' => dbquery('SELECT * FROM users WHERE steamid="' . escapestring($request->steamid) . '" AND community="' . userCommunity($_SESSION['steamid']) . '"')[0]));
    } else {
        throw Klein\Exceptions\HttpException::createFromCode(404);
    }
});

// Database Backup API
$klein->respond('GET', '/api/backup', function ($request, $response, $service) {
    if (!isCron()) {
        throw Klein\Exceptions\HttpException::createFromCode(404);
    }
    exec('mysqldump --user=' . $GLOBALS['mysql_user'] . ' --password=' . $GLOBALS['mysql_pass'] . ' --host=' . $GLOBALS['mysql_host'] . ' ' . $GLOBALS['mysql_db'] . ' > backups/' . time() . '.sql');
});

$klein->respond('GET', '/api/[staff|players|playerslist|warnslist|kickslist|commendslist|banslist|servers|bans|warns|kicks|cron|userdata|adduser|trustscore|message|recentchart:action]', function ($request, $response, $service) {
    header('Content-Type: application/json');
    if($request->param('community') == "" || is_null($request->param('community'))) {
        if($request->action != "cron") {
            echo json_encode(array('error'=>'Missing Parameter','details'=>'Community ID Missing'));
            exit();
        }
    } else {
        $community = escapestring($request->param('community'));
    }
    switch ($request->action) {
        case "staff":
            echo json_encode(dbquery('SELECT name, steamid, rank FROM users WHERE rank != "user" AND community="' . $community . '"'));
            break;
        case "players":
            echo json_encode(dbquery('SELECT * FROM players WHERE community="' . $community . '"'));
            break;
        case "playerslist":
            $columns = array(
                array('db' => 'name', 'dt' => 0),
                array(
                    'db' => 'playtime',
                    'dt' => 1,
                    'formatter' => function ($d, $row) {
                        return secsToStr($d * 60);
                    },
                ),
                array(
                    'db' => 'license',
                    'dt' => 2,
                    'formatter' => function ($d2, $row2) {
                        return trustScore($d2, $community) . '%';
                    },
                ),
                array(
                    'db' => 'firstjoined',
                    'dt' => 3,
                    'formatter' => function ($d, $row) {
                        return date("m/d/Y h:i A", $d);
                    },
                ),
                array(
                    'db' => 'lastplayed',
                    'dt' => 4,
                    'formatter' => function ($d, $row) {
                        return date("m/d/Y h:i A", $d);
                    },
                ),
                array('db' => 'license', 'dt' => -1),
            );

            $sql_details = array(
                'user' => $GLOBALS['mysql_user'],
                'pass' => $GLOBALS['mysql_pass'],
                'db' => $GLOBALS['mysql_db'],
                'host' => $GLOBALS['mysql_host'],
            );

            require ('app/main/ssp.class.php');

            echo json_encode(
                SSP::complex($_GET, $sql_details, 'players', 'ID', $columns, null, "community='" . userCommunity($_SESSION['steamid']) . "'")
            );
            break;
        
        case "trustscore":
            if ($request->param('license') == null) {
                echo json_encode(array("response" => "400", "message" => "Missing Player Identifier"));
            } else {
                $users = dbquery('SELECT license FROM players WHERE license="' . escapestring($request->param('license')) . '" AND community="' . $community . '"');
                if (!empty($users)) {
                    echo json_encode(array(
                        "trustscore" => trustScore($users[0]['license'], $community)
                    ));
                } else {
                    echo json_encode(array(
                        "trustscore" => 75
                    ));
                }
            }
            break;
        case "commendslist":
            $columns = array(
                array(
                    'db' => 'license',
                    'dt' => 0,
                    'formatter' => function ($d, $row) {
                        return dbquery('SELECT * FROM players WHERE license="' . $d . '" AND community="' . userCommunity($_SESSION['steamid']) . '"')[0]['name'];
                    },
                ),
                array('db' => 'reason', 'dt' => 1),
                array('db' => 'staff_name', 'dt' => 2),
                array(
                    'db' => 'time',
                    'dt' => 3,
                    'formatter' => function ($d, $row) {
                        return date("m/d/Y h:i A", $d);
                    },
                ),
                array('db' => 'license', 'dt' => -1),
            );

            $sql_details = array(
                'user' => $GLOBALS['mysql_user'],
                'pass' => $GLOBALS['mysql_pass'],
                'db' => $GLOBALS['mysql_db'],
                'host' => $GLOBALS['mysql_host'],
            );

            require ('app/main/ssp.class.php');

            echo json_encode(
                SSP::complex($_GET, $sql_details, 'commend', 'ID', $columns, null, "community='" . userCommunity($_SESSION['steamid']) . "'")
            );
            break;
        case "warnslist":
            $columns = array(
                array(
                    'db' => 'license',
                    'dt' => 0,
                    'formatter' => function ($d, $row) {
                        return dbquery('SELECT * FROM players WHERE license="' . $d . '" AND community="' . userCommunity($_SESSION['steamid']) . '"')[0]['name'];
                    },
                ),
                array('db' => 'reason', 'dt' => 1),
                array('db' => 'staff_name', 'dt' => 2),
                array(
                    'db' => 'time',
                    'dt' => 3,
                    'formatter' => function ($d, $row) {
                        return date("m/d/Y h:i A", $d);
                    },
                ),
                array('db' => 'license', 'dt' => -1),
            );

            $sql_details = array(
                'user' => $GLOBALS['mysql_user'],
                'pass' => $GLOBALS['mysql_pass'],
                'db' => $GLOBALS['mysql_db'],
                'host' => $GLOBALS['mysql_host'],
            );

            require ('app/main/ssp.class.php');

            echo json_encode(
                SSP::complex($_GET, $sql_details, 'warnings', 'ID', $columns, null, "community='" . userCommunity($_SESSION['steamid']) . "'")
            );
            break;
        case "kickslist":
            $columns = array(
                array(
                    'db' => 'license',
                    'dt' => 0,
                    'formatter' => function ($d, $row) {
                        return dbquery('SELECT * FROM players WHERE license="' . $d . '" AND community="' . userCommunity($_SESSION['steamid']) . '"')[0]['name'];
                    },
                ),
                array('db' => 'reason', 'dt' => 1),
                array('db' => 'staff_name', 'dt' => 2),
                array(
                    'db' => 'time',
                    'dt' => 3,
                    'formatter' => function ($d, $row) {
                        return date("m/d/Y h:i A", $d);
                    },
                ),
                array('db' => 'license', 'dt' => -1),
            );

            $sql_details = array(
                'user' => $GLOBALS['mysql_user'],
                'pass' => $GLOBALS['mysql_pass'],
                'db' => $GLOBALS['mysql_db'],
                'host' => $GLOBALS['mysql_host'],
            );

            require ('app/main/ssp.class.php');

            echo json_encode(
                SSP::complex($_GET, $sql_details, 'kicks', 'ID', $columns, null, "community='" . userCommunity($_SESSION['steamid']) . "'")
            );
            break;
        case "banslist":
            $columns = array(
                array('db' => 'name', 'dt' => 0),
                array('db' => 'reason', 'dt' => 1),
                array('db' => 'staff_name', 'dt' => 2),
                array(
                    'db' => 'ban_issued',
                    'dt' => 3,
                    'formatter' => function ($d, $row) {
                        return date("m/d/Y h:i A", $d);
                    },
                ),
                array(
                    'db' => 'banned_until',
                    'dt' => 4,
                    'formatter' => function ($d, $row) {
                        if($d == 0) {
                            return "Permanent";
                        } else {
                            return date("m/d/Y h:i A", $d);
                        }
                    },
                ),
                array('db' => 'identifier', 'dt' => -1),
            );

            $sql_details = array(
                'user' => $GLOBALS['mysql_user'],
                'pass' => $GLOBALS['mysql_pass'],
                'db' => $GLOBALS['mysql_db'],
                'host' => $GLOBALS['mysql_host'],
            );

            require ('app/main/ssp.class.php');

            echo json_encode(
                SSP::complex($_GET, $sql_details, 'bans', 'ID', $columns, null, "community='" . userCommunity($_SESSION['steamid']) . "'")
            );
            break;
        case "servers":
            echo json_encode(dbquery('SELECT ID, name, connection FROM servers WHERE community="' . $community . '"'));
            break;
        case "cron":
            if (!isCron()) {
                throw Klein\Exceptions\HttpException::createFromCode(404);
            }
            $starttime = microtime(true);
            $servers = dbquery('SELECT * FROM servers');
            $playercount = 0;
            $servercount = 0;
            foreach ($servers as $server) {
                if($servercount % 20 == 0) {
                    sleep(1);
                }
                if ( preg_match('/\s/',$server['connection']) ) {
                    // Contains Spaces - Stop Worker
                } else {
                    exec($GLOBALS['phpbin'] . ' worker.php ' . $server['connection'] . ' ' . $server['community'] . ' > execoutput.txt 2>&1 &');
                }
                $servercount++;
            }
            $endtime = microtime(true);
            echo json_encode(array('status'=>'200', 'message'=>'Successful', 'loadtime'=>($endtime - $starttime), 'servers'=>count($servers)));
            break;
        case "bans":
            echo json_encode(dbquery('SELECT name, identifier, reason, ban_issued, banned_until, staff_name, staff_steamid FROM bans WHERE community="' . $community . '"'));
            break;
        case "warns":
            echo json_encode(dbquery('SELECT license, reason, staff_name, staff_steamid, time FROM warnings WHERE community="' . $community . '"'));
            break;
        case "kicks":
            echo json_encode(dbquery('SELECT license, reason, staff_name, staff_steamid, time FROM kicks WHERE community="' . $community . '"'));
            break;
        case "userdata";
            if ($request->param('license') == null) {
                echo json_encode(array("response" => "400", "message" => "Missing Player Identifier"));
            } else {
                $userinfo = array();

                // Get Users Player Data
                $temp = dbquery('SELECT name, license, steam, playtime, firstjoined, lastplayed FROM players WHERE license="' . escapestring($request->param('license')) . '" AND community="' . $community . '"')[0];
                if ($temp != null) {
                    $userinfo = array_merge($userinfo, $temp);
                }

                // Get Users Trust Score
                $temp = trustScore(escapestring($request->param('license')), $community);
                if ($temp != null) {
                    $userinfo = array_merge($userinfo, array("trustscore"=>"" . $temp . ""));
                }
                
                // Get Users Bans
                $bans = dbquery('SELECT reason, ban_issued, banned_until, staff_name FROM bans WHERE identifier="' . escapestring($request->param('license')) . '" AND (banned_until >= ' . time() . ' OR banned_until = 0) AND community="' . $community . '"');
                if (!empty($bans)) {
                    if ($bans[0]['banned_until'] == 0) {
                        $banned_until = "Permanent";
                    } else {
                        $banned_until = date("m/d/Y h:i A T", $bans[0]['banned_until']);
                    }
                    $userinfo = array_merge($userinfo, array(
                        "banned" => "true",
                        "reason" => $bans[0]['reason'],
                        "staff" => $bans[0]['staff_name'],
                        "ban_issued" => date("m/d/Y h:i A T", $bans[0]['ban_issued']),
                        "banned_until" => $banned_until,
                    ));
                } else {
                    $userinfo = array_merge($userinfo, array("banned" => "false"));
                }
                
                // Return User Data
                echo json_encode($userinfo);
            }
            break;
        case "adduser":
            if ($request->param('license') == null || $request->param('name') == null) {
                echo json_encode(array("response" => "400", "message" => "Missing Parameters"));
            } else {
                dbquery('INSERT INTO players (name, license, playtime, firstjoined, lastplayed, community) VALUES ("' . escapestring($request->param('name')) . '", "' . escapestring($request->param('license')) . '", "0", "' . time() . '", "' . time() . '", "' . $community . '") ON DUPLICATE KEY UPDATE name="' . escapestring($request->param('name')) . '"', false);
                echo json_encode(array("response" => "200", "message" => "Successfully added user into database."));
                if (siteConfig('joinmessages') == "true") {
                    sendMessage('^3' . $request->param('name') . '^0 is joining the server with ^2' . trustScore($request->param('license'), $community) . '%^0 trust score.');
                }
            }
            break;
        case "message":
            if ($request->param('id') == null || $request->param('message') == null) {
                echo json_encode(array("response" => "400", "message" => "Missing Parameters"));
            } else {
                switch ($request->param('message')) {
                    case strpos($request->param('message'), "/warn ") === 0:
                        $staff = dbquery('SELECT * FROM players WHERE license="' . escapestring($request->param('id')) . '" AND community="' . $community . '"');
                        if (hasPermission(hex2dec(strtoupper(str_replace('steam:', '', $staff[0]['steam']))), "warn", $community)) {
                            $input = str_replace('/warn ', '', $request->param('message'));
                            $params = explode(' ', $input, 2);
                            foreach (dbquery('SELECT * FROM servers WHERE community="' . $community . '"') as $server) {
                                if (checkOnline($server['connection']) == true) {
                                    $players = serverInfo($server['connection'])['players'];
                                    foreach ($players as $player) {
                                        if ($player->identifiers[1] == escapestring($request->param('id'))) {
                                            foreach ($players as $player) {
                                                if ($player->id == $params[0]) {
                                                    dbquery('INSERT INTO warnings (license, reason, staff_name, staff_steamid, time, community) VALUES ("' . $player->identifiers[1] . '", "' . escapestring($params[1]) . '", "' . $staff[0]['name'] . '", "' . hex2dec(strtoupper(str_replace('steam:', '', $staff[0]['steam']))) . '", "' . time() . '", "' . $community . '")', false);
                                                    sendMessage('^3' . $player->name . '^0 has been warned by ^2' . $staff[0]['name'] . '^0 for ^3' . escapestring($params[1]), $server);
                                                    discordMessage('Player Warned', '**Player: **' . $player->name . '\r\n**Reason: **' . $params[1] . '\r\n**Warned By: **' . $staff[0]['name'], $community);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        break;
                    case strpos($request->param('message'), "/kick ") === 0:
                        $staff = dbquery('SELECT * FROM players WHERE license="' . escapestring($request->param('id')) . '" AND community="' . $community . '"');
                        if (hasPermission(hex2dec(strtoupper(str_replace('steam:', '', $staff[0]['steam']))), "kick", $community)) {
                            $input = str_replace('/kick ', '', $request->param('message'));
                            $params = explode(' ', $input, 2);
                            foreach (dbquery('SELECT * FROM servers WHERE community="' . $community . '"') as $server) {
                                if (checkOnline($server['connection']) == true) {
                                    $players = serverInfo($server['connection'])['players'];
                                    foreach ($players as $player) {
                                        if ($player->identifiers[1] == escapestring($request->param('id'))) {
                                            foreach ($players as $player) {
                                                if ($player->id == $params[0]) {
                                                    dbquery('INSERT INTO kicks (license, reason, staff_name, staff_steamid, time, community) VALUES ("' . $player->identifiers[1] . '", "' . escapestring($params[1]) . '", "' . $staff[0]['name'] . '", "' . hex2dec(strtoupper(str_replace('steam:', '', $staff[0]['steam']))) . '", "' . time() . '", "' . $community . '")', false);
                                                    removeFromSession($player->identifiers[1], "You were kicked by " . $staff[0]['name'] . " for " . $params[1], $server);
                                                    sendMessage('^3' . $player->name . '^0 has been kicked by ^2' . $staff[0]['name'] . '^0 for ^3' . escapestring($params[1]), $server);
                                                    discordMessage('Player Kicked', '**Player: **' . $player->name . '\r\n**Reason: **' . $params[1] . '\r\n**Kicked By: **' . $staff[0]['name'], $community);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        break;
                    case strpos($request->param('message'), "/note ") === 0:
                        $staff = dbquery('SELECT * FROM players WHERE license="' . escapestring($request->param('id')) . '" AND community="' . $community . '"');
                        if (hasPermission(hex2dec(strtoupper(str_replace('steam:', '', $staff[0]['steam']))), "note", $community)) {
                            $input = str_replace('/note ', '', $request->param('message'));
                            $params = explode(' ', $input, 2);
                            foreach (dbquery('SELECT * FROM servers WHERE community="' . $community . '"') as $server) {
                                if (checkOnline($server['connection']) == true) {
                                    $players = serverInfo($server['connection'])['players'];
                                    foreach ($players as $player) {
                                        if ($player->identifiers[1] == escapestring($request->param('id'))) {
                                            foreach ($players as $player) {
                                                if ($player->id == $params[0]) {
                                                    dbquery('INSERT INTO notes (license, reason, staff_name, staff_steamid, time, community) VALUES ("' . $player->identifiers[1] . '", "' . escapestring($params[1]) . '", "' . $staff[0]['name'] . '", "' . hex2dec(strtoupper(str_replace('steam:', '', $staff[0]['steam']))) . '", "' . time() . '", "' . $community . '")', false);
                                                    sendMessage('^3Note Added to Player', $server);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        break;
                    case strpos($request->param('message'), "/ban ") === 0:
                        $staff = dbquery('SELECT * FROM players WHERE license="' . escapestring($request->param('id')) . '" AND community="' . $community . '"');
                        if (hasPermission(hex2dec(strtoupper(str_replace('steam:', '', $staff[0]['steam']))), "ban", $community)) {
                            $input = str_replace('/ban ', '', $request->param('message'));
                            $params = explode(' ', $input, 3);
                            foreach (dbquery('SELECT * FROM servers WHERE community="' . $community . '"') as $server) {
                                if (checkOnline($server['connection']) == true) {
                                    $players = serverInfo($server['connection'])['players'];
                                    foreach ($players as $player) {
                                        if ($player->identifiers[1] == escapestring($request->param('id'))) {
                                            foreach ($players as $player) {
                                                if ($player->id == $params[0]) {
                                                    $time = 0;
                                                    if (isset($params[1])) {
                                                        $length = preg_split('/(?<=[0-9])(?=[a-z]+)/i', $params[1]);
                                                        if ($length[0] != 0) {
                                                            switch ($length[1]) {
                                                                case "m":
                                                                    $time = 60;
                                                                    break;
                                                                case "h":
                                                                    $time = 3600;
                                                                    break;
                                                                case "d":
                                                                    $time = 86400;
                                                                    break;
                                                                case "w":
                                                                    $time = 604800;
                                                                    break;
                                                                default:
                                                                    $time = 86400;
                                                                    break;
                                                            }
                                                        } else {
                                                            $time = 0;
                                                        }
    
                                                        $daycount = secsToStr($length[0] * $time);
                                                        if ($time == 0) {
                                                            $banned_until = 0;
                                                            sendMessage('^3' . $player->name . '^0 has been permanently banned by ^2' . $staff[0]['name'] . '^0 for ^3' . $params[2], $server);
                                                            discordMessage('Player Banned', '**Player: **' . $player->name . '\r\n**Reason: **' . $params[2] . '\r\n**Ban Length: **Permanent\r\n**Banned By: **' . $staff[0]['name'], $community);
                                                        } else {
                                                            $banned_until = time() + ($length[0] * $time);
                                                            sendMessage('^3' . $player->name . '^0 has been banned for ^3' . $daycount . '^0 by ^2' . $staff[0]['name'] . '^0 for ^3' . $params[2], $server);
                                                            discordMessage('Player Banned', '**Player: **' . $player->name . '\r\n**Reason: **' . $params[2] . '\r\n**Ban Length: **' . secsToStr($length[0] * $time) . '\r\n**Banned By: **' . $staff[0]['name'], $community);
                                                        }
                                                        dbquery('INSERT INTO bans (name, identifier, reason, ban_issued, banned_until, staff_name, staff_steamid, community) VALUES ("' . escapestring($player->name) . '", "' . escapestring($player->identifiers[1]) . '", "' . escapestring($params[2]) . '", "' . time() . '", "' . $banned_until . '", "' . $staff[0]['name'] . '", "' . hex2dec(strtoupper(str_replace('steam:', '', $staff[0]['steam']))) . '", "' . $community . '")', false);
                                                        removeFromSession($player->identifiers[1], "You were banned by " . $staff[0]['name'] . " for " . $params[3] . " (Relog for more info)", $server);
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        break;       
                    case strpos($request->param('message'), "/trustscore ") === 0:
                        $input = str_replace('/trustscore ', '', $request->param('message'));
                        foreach (dbquery('SELECT * FROM servers WHERE community="' . $community . '"') as $server) {
                            if (checkOnline($server['connection']) == true) {
                                $players = serverInfo($server['connection'])['players'];
                                foreach ($players as $player) {
                                    if ($player->identifiers[1] == escapestring($request->param('id'))) {
                                        foreach ($players as $player) {
                                            if ($player->id == $input) {
                                                $playerinfo = dbquery('SELECT * FROM players WHERE license="' . $player->identifiers[1] . '" AND community="' . $community . '"');
                                                sendMessage('^3' . $player->name . '^0 has a playtime of ^2' . secsToStr($playerinfo[0]['playtime'] * 60) . '^0 and a trustscore of ^2' . trustScore($player->identifiers[1], $community) . '%', $server);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        break;
                    case strpos($request->param('message'), "/commend ") === 0:
                        $staff = dbquery('SELECT * FROM players WHERE license="' . escapestring($request->param('id')) . '" AND community="' . $community . '"');
                        if (hasPermission(hex2dec(strtoupper(str_replace('steam:', '', $staff[0]['steam']))), "commend", $community)) {
                            $input = str_replace('/commend ', '', $request->param('message'));
                            $params = explode(' ', $input, 2);
                            foreach (dbquery('SELECT * FROM servers WHERE community="' . $community . '"') as $server) {
                                if (checkOnline($server['connection']) == true) {
                                    $players = serverInfo($server['connection'])['players'];
                                    foreach ($players as $player) {
                                        if ($player->identifiers[1] == escapestring($request->param('id'))) {
                                            foreach ($players as $player) {
                                                if ($player->id == $params[0]) {
                                                    dbquery('INSERT INTO commend (license, reason, staff_name, staff_steamid, time, community) VALUES ("' . $player->identifiers[1] . '", "' . escapestring($params[1]) . '", "' . $staff[0]['name'] . '", "' . hex2dec(strtoupper(str_replace('steam:', '', $staff[0]['steam']))) . '", "' . time() . '", "' . $community . '")', false);
                                                    sendMessage('^3' . $player->name . '^0 has been commended by ^2' . $staff[0]['name'] . '^0 for ^3' . escapestring($params[1]), $server);
                                                    discordMessage('Player Commended', '**Player: **' . $player->name . '\r\n**Reason: **' . $params[1] . '\r\n**Commended By: **' . $staff[0]['name'], $community);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        break;
                }
            }
            break;
        case "recentchart":
            $weekprior = time() - 604800;
            $recentwarns = dbquery('SELECT * FROM warnings WHERE time>="' . $weekprior . '" AND community="' . userCommunity($_SESSION['steamid']) . '"');
            $recentkicks = dbquery('SELECT * FROM kicks WHERE time>="' . $weekprior . '" AND community="' . userCommunity($_SESSION['steamid']) . '"');
            $recentbans = dbquery('SELECT * FROM bans WHERE ban_issued>="' . $weekprior . '" AND community="' . userCommunity($_SESSION['steamid']) . '"');
            echo json_encode($recentbans);
            break;
    }
});

$klein->respond('POST', '/api/button/[restart|kickforstaff|command:action]', function ($request, $response, $service) {
    header('Content-Type: application/json');
    if (isset($_SESSION['steamid'])) {
        if (getRank($_SESSION['steamid']) != "user") {
            switch ($request->action) {
                case "restart":
                    if ($request->param('input') == null || $request->param('server') == null) {
                        echo json_encode(array("response" => "400", "message" => "Invalid API Endpoint"));
                    } else {
                        $server = dbquery('SELECT * FROM servers WHERE connection="' . escapestring($request->param('server')) . '" AND community="' . userCommunity($_SESSION['steamid']) . '"');
                        if (!empty($server)) {
                            if (checkOnline($server[0]['connection']) == true) {
                                $con = new q3query(strtok($server[0]['connection'], ':'), str_replace(':', '', substr($server[0]['connection'], strpos($server[0]['connection'], ':'))), $success);
                                $con->setRconpassword($server[0]['rcon']);
                                $con->rcon("restart " . $request->param('input'));
                                echo json_encode(array('success' => true, 'reload' => true));
                            }
                        }
                    }
                    break;
                case "command":
                    if ($request->param('input') == null || $request->param('server') == null) {
                        echo json_encode(array("response" => "400", "message" => "Invalid API Endpoint"));
                    } else {
                        $server = dbquery('SELECT * FROM servers WHERE connection="' . escapestring($request->param('server')) . '" AND community="' . userCommunity($_SESSION['steamid']) . '"');
                        if (!empty($server)) {
                            if (checkOnline($server[0]['connection']) == true) {
                                $con = new q3query(strtok($server[0]['connection'], ':'), str_replace(':', '', substr($server[0]['connection'], strpos($server[0]['connection'], ':'))), $success);
                                $con->setRconpassword($server[0]['rcon']);
                                $con->rcon($request->param('input'));
                                echo json_encode(array('success' => true, 'reload' => true));
                            }
                        }
                    }
                    break;
                case "kickforstaff":
                    if ($request->param('server') == null) {
                        echo json_encode(array("response" => "400", "message" => "Invalid API Endpoint"));
                    } else {
                        $server = dbquery('SELECT * FROM servers WHERE connection="' . escapestring($request->param('server')) . '" AND community="' . userCommunity($_SESSION['steamid']) . '"');
                        if (!empty($server)) {
                            if (checkOnline($server[0]['connection']) == true) {
                                $serverinfo = json_decode(@file_get_contents('http://' . $server[0]['connection'] . '/players.json'));
                                $con = new q3query(strtok($server[0]['connection'], ':'), str_replace(':', '', substr($server[0]['connection'], strpos($server[0]['connection'], ':'))), $success);
                                sort($serverinfo);
                                $kickplayer = null;
                                foreach ($serverinfo as $player) {
                                    $kickplayer = $player;
                                }
                                $con->setRconpassword($server[0]['rcon']);
                                $con->rcon('staff_sayall ^3' . $kickplayer->name . '^0 has been kicked by ^2' . $_SESSION['steam_personaname'] . '^0 to make room for staff.');
                                discordMessage('Kick For Staff', '**Staff Member: **' . $_SESSION['steam_personaname']);
                                $con->rcon('clientkick ' . $kickplayer->id . ' Kicked for Reserved Staff Slot');
                                echo json_encode(array('success' => true, 'reload' => true));
                            }
                        }
                    }
                    break;
            }
        } else {
            echo json_encode(array("response" => "403", "message" => "User rank does not have access to POST API."));
        }
    } else {
        echo json_encode(array("response" => "401", "message" => "Unauthenticated API request."));
    }
});

$klein->respond('POST', '/api/[warn|kick|ban|commend|note|addserver|addcommunity|delcommunity|updatepanel|delserver|addstaff|delstaff|delwarn|delcommend|delnote|delkick|delban:action]', function ($request, $response, $service) {
    header('Content-Type: application/json');
    if (isset($_SESSION['steamid'])) {
        if (getRank($_SESSION['steamid']) != "user" || $request->action == "addcommunity") {
            switch ($request->action) {
                case "warn":
                    if (!hasPermission($_SESSION['steamid'], 'warn')) {
                        echo json_encode(array('message' => 'You do not have permission to warn!'));
                        exit();
                    }
                    if ($request->param('name') == null || $request->param('license') == null || $request->param('reason') == null) {
                        echo json_encode(array('message' => 'Please fill in all of the fields!'));
                    } else {
                        dbquery('INSERT INTO warnings (license, reason, staff_name, staff_steamid, time, community) VALUES ("' . escapestring($request->param('license')) . '", "' . escapestring($request->param('reason')) . '", "' . $_SESSION['steam_personaname'] . '", "' . $_SESSION['steamid'] . '", "' . time() . '", "' . userCommunity($_SESSION['steamid']) . '")', false);
                        sendMessage('^3' . $request->param('name') . '^0 has been warned by ^2' . $_SESSION['steam_personaname'] . '^0 for ^3' . $request->param('reason'));
                        if (!empty(siteConfig('discord_webhook'))) {
                            discordMessage('Player Warned', '**Player: **' . $request->param('name') . '\r\n**Reason: **' . $request->param('reason') . '\r\n**Warned By: **' . $_SESSION['steam_personaname']);
                        }
                        echo json_encode(array('success' => true, 'reload' => true));
                    }
                    break;
                case "kick":
                    if (!hasPermission($_SESSION['steamid'], 'kick')) {
                        echo json_encode(array('message' => 'You do not have permission to kick!'));
                        exit();
                    }
                    if ($request->param('name') == null || $request->param('license') == null || $request->param('reason') == null) {
                        echo json_encode(array('message' => 'Please fill in all of the fields!'));
                    } else {
                        dbquery('INSERT INTO kicks (license, reason, staff_name, staff_steamid, time, community) VALUES ("' . escapestring($request->param('license')) . '", "' . escapestring($request->param('reason')) . '", "' . $_SESSION['steam_personaname'] . '", "' . $_SESSION['steamid'] . '", "' . time() . '", "' . userCommunity($_SESSION['steamid']) . '")', false);
                        removeFromSession($request->param('license'), "You were kicked by " . $_SESSION['steam_personaname'] . " for " . $request->param('reason'));
                        sendMessage('^3' . $request->param('name') . '^0 has been kicked by ^2' . $_SESSION['steam_personaname'] . '^0 for ^3' . $request->param('reason'));
                        if (!empty(siteConfig('discord_webhook'))) {
                            discordMessage('Player Kicked', '**Player: **' . $request->param('name') . '\r\n**Reason: **' . $request->param('reason') . '\r\n**Kicked By: **' . $_SESSION['steam_personaname']);
                        }
                        echo json_encode(array('success' => true, 'reload' => true));
                    }
                    break;
                case "ban":
                    if (!hasPermission($_SESSION['steamid'], 'ban')) {
                        echo json_encode(array('message' => 'You do not have permission to ban!'));
                        exit();
                    }
                    if ($request->param('name') == null || $request->param('license') == null || $request->param('reason') == null || $request->param('banlength') == null) {
                        echo json_encode(array('message' => 'Please fill in all of the fields!'));
                    } else {
                        if ($request->param('banlength') == 0) {
                            $banned_until = 0;
                            sendMessage('^3' . $request->param('name') . '^0 has been permanently banned by ^2' . $_SESSION['steam_personaname'] . '^0 for ^3' . $request->param('reason'));
                        } else {
                            $banned_until = time() + $request->param('banlength');
                            sendMessage('^3' . $request->param('name') . '^0 has been banned for ' . secsToStr($request->param('banlength')) . ' by ^2' . $_SESSION['steam_personaname'] . '^0 for ^3' . $request->param('reason'));
                        }
                        dbquery('INSERT INTO bans (name, identifier, reason, ban_issued, banned_until, staff_name, staff_steamid, community) VALUES ("' . escapestring($request->param('name')) . '", "' . escapestring($request->param('license')) . '", "' . escapestring($request->param('reason')) . '", "' . time() . '", "' . $banned_until . '", "' . $_SESSION['steam_personaname'] . '", "' . $_SESSION['steamid'] . '", "' . userCommunity($_SESSION['steamid']) . '")', false);
                        removeFromSession($request->param('license'), "Banned by " . $_SESSION['steam_personaname'] . " for " . $request->param('reason') . " (Relog for more information)");
                        if (!empty(siteConfig('discord_webhook'))) {
                            if ($request->param('banlength') == 0) {
                                $banlength = "Permanent";
                            } else {
                                $banlength = secsToStr($request->param('banlength'));
                            }
                            discordMessage('Player Banned', '**Player: **' . $request->param('name') . '\r\n**Reason: **' . $request->param('reason') . '\r\n**Ban Length: **' . $banlength . '\r\n**Banned By: **' . $_SESSION['steam_personaname']);
                        }
                        echo json_encode(array('success' => true, 'reload' => true));
                    }
                    break;
                case "commend":
                    if (!hasPermission($_SESSION['steamid'], 'commend')) {
                        echo json_encode(array('message' => 'You do not have permission to commend!'));
                        exit();
                    }
                    if ($request->param('name') == null || $request->param('license') == null || $request->param('reason') == null) {
                        echo json_encode(array('message' => 'Please fill in all of the fields!'));
                    } else {
                        dbquery('INSERT INTO commend (license, reason, staff_name, staff_steamid, time, community) VALUES ("' . escapestring($request->param('license')) . '", "' . escapestring($request->param('reason')) . '", "' . $_SESSION['steam_personaname'] . '", "' . $_SESSION['steamid'] . '", "' . time() . '", "' . userCommunity($_SESSION['steamid']) . '")', false);
                        sendMessage('^3' . $request->param('name') . '^0 has been commended by ^2' . $_SESSION['steam_personaname'] . '^0 for ^3' . $request->param('reason'));
                        if (!empty(siteConfig('discord_webhook'))) {
                            discordMessage('Player Commended', '**Player: **' . $request->param('name') . '\r\n**Reason: **' . $request->param('reason') . '\r\n**Commended By: **' . $_SESSION['steam_personaname']);
                        }
                        echo json_encode(array('success' => true, 'reload' => true));
                    }
                    break;
                case "note":
                    if (!hasPermission($_SESSION['steamid'], 'note')) {
                        echo json_encode(array('message' => 'You do not have permission to add a note!'));
                        exit();
                    }
                    if ($request->param('name') == null || $request->param('license') == null || $request->param('reason') == null) {
                        echo json_encode(array('message' => 'Please fill in all of the fields!'));
                    } else {
                        dbquery('INSERT INTO notes (license, reason, staff_name, staff_steamid, time, community) VALUES ("' . escapestring($request->param('license')) . '", "' . escapestring($request->param('reason')) . '", "' . $_SESSION['steam_personaname'] . '", "' . $_SESSION['steamid'] . '", "' . time() . '", "' . userCommunity($_SESSION['steamid']) . '")', false);
                        if (!empty(siteConfig('discord_webhook'))) {
                            discordMessage('Player Noted', '**Player: **' . $request->param('name') . '\r\n**Note: **' . $request->param('reason') . '\r\n**Note Added By: **' . $_SESSION['steam_personaname']);
                        }
                        echo json_encode(array('success' => true, 'reload' => true));
                    }
                    break;
                case "addserver":
                    if (!hasPermission($_SESSION['steamid'], 'editservers')) {
                        echo json_encode(array('message' => 'You do not have permission to edit servers!'));
                        exit();
                    }
                    if ($request->param('servername') == null || $request->param('serverip') == null || $request->param('serverport') == null || $request->param('serverrcon') == null) {
                        echo json_encode(array('message' => 'Please fill in all of the fields!'));
                    } else {
                        if ($request->param('serverip') == "localhost" || $request->param('serverip') == "127.0.0.1" || $request->param('serverip') == "0.0.0.0") {
                            echo json_encode(array('message' => 'Invalid Server IP. Make sure you are using an external IP address.'));
                            exit();
                        }
                        dbquery('INSERT INTO servers (name, connection, rcon, community) VALUES ("' . $request->param('servername') . '", "' . $request->param('serverip') . ':' . $request->param('serverport') . '", "' . $request->param('serverrcon') . '", "' . userCommunity($_SESSION['steamid']) . '")', false);
                        staffDiscordMessage('New Server', '**Server Name: **' . $request->param('servername') . '\nServer: ' . $request->param('serverip') . ':' . $request->param('serverport'));
                        echo json_encode(array('success' => true, 'reload' => true));
                    }
                    break;
                case "addcommunity":
                    if ($request->param('communityname') == null) {
                        echo json_encode(array('message' => 'Please fill in all of the fields!'));
                    } else {
                        // Create Community Unique ID/API Key
                        $communityid = createUniqueID(32);

                        // Create Community
                        dbquery('INSERT INTO communities (name, owner, time, uniqueid) VALUES ("' . escapestring($request->param('communityname')) . '", "' . escapestring($_SESSION['steamid']) . '", "' . time() . '", "' . $communityid . '")', false);
                        
                        // Set Default Panel Config
                        dbquery('INSERT INTO config (
                            community_name,
                            discord_webhook,
                            joinmessages,
                            chatcommands,
                            trustscore,
                            tswarn,
                            tskick,
                            tsban,
                            tscommend,
                            tstime,
                            recent_time,
                            checktimeout,
                            permissions,
                            serveractions,
                            community
                        ) VALUES (
                            "' . escapestring($request->param('communityname')) . '",
                            "",
                            "false",
                            "true",
                            "75",
                            "3",
                            "6",
                            "10",
                            "2",
                            "1",
                            "10",
                            "5",
                            \'O:8:"stdClass":4:{s:5:"owner";a:9:{i:0;s:4:"warn";i:1;s:4:"kick";i:2;s:3:"ban";i:3;s:7:"commend";i:4;s:4:"note";i:5;s:9:"editpanel";i:6;s:9:"editstaff";i:7;s:11:"editservers";i:8;s:9:"delrecord";}s:11:"senioradmin";a:5:{i:0;s:4:"warn";i:1;s:4:"kick";i:2;s:3:"ban";i:3;s:7:"commend";i:4;s:4:"note";}s:5:"admin";a:4:{i:0;s:4:"warn";i:1;s:4:"kick";i:2;s:7:"commend";i:3;s:4:"note";}s:9:"moderator";a:3:{i:0;s:4:"warn";i:1;s:7:"commend";i:2;s:4:"note";}}\',
                            \'O:8:"stdClass":1:{s:18:"108.61.69.48:30120";O:8:"stdClass":1:{s:14:"UniqueNameHere";O:8:"stdClass":4:{s:6:"action";s:7:"command";s:5:"input";s:17:"say Hello Server!";s:10:"buttonname";s:19:"Say Hello to Server";s:11:"buttonstyle";s:11:"btn-success";}}}\',
                            "' . $communityid . '"
                        )', false);

                        // Alert Staff
                        staffDiscordMessage('New Community', '**Community Name: **' . $request->param('communityname') . '\n**Community ID: **' . $communityid);

                        // Set Creator as Owner
                        dbquery('UPDATE users SET rank="owner", community="' . $communityid . '" WHERE steamid="' . $_SESSION['steamid'] . '"', false);                        
                        echo json_encode(array('success' => true, 'reload' => true));
                    }
                    break;
                case "updatepanel":
                    if (!hasPermission($_SESSION['steamid'], 'editpanel')) {
                        echo json_encode(array('message' => 'You do not have permission to edit the panel!'));
                        exit();
                    } else {

                        if(escapestring(serialize(json_decode($_POST['permissions']))) == "N;") {
                            echo json_encode(array('success' => false, 'message' => 'Your permissions field failed to validate (Check Syntax)'));
                            exit();
                        }

                        if(escapestring(serialize(json_decode($_POST['serveractions']))) == "N;") {
                            echo json_encode(array('success' => false, 'message' => 'Your server buttons field failed to validate (Check Syntax)'));
                            exit();
                        }

                        if($_POST['joinmessages'] != "true" && $_POST['joinmessages'] != "false") {
                            echo json_encode(array('success' => false, 'message' => 'Join Messages field incorrect input. (true/false)'));
                            exit();
                        }

                        if($_POST['chatcommands'] != "true" && $_POST['chatcommands'] != "false") {
                            echo json_encode(array('success' => false, 'message' => 'Chat Commands field incorrect input. (true/false)'));
                            exit();
                        }

                        if($_POST['checktimeout'] > 25) {
                            echo json_encode(array('success' => false, 'message' => 'Timeout larger than 25 seconds.'));
                            exit();
                        }

                        dbquery('UPDATE config SET
                        community_name = "' . escapestring($_POST['communityname']) . '",
                        discord_webhook = "' . escapestring($_POST['discordwebhook']) . '",
                        joinmessages = "' . escapestring($_POST['joinmessages']) . '",
                        chatcommands = "' . escapestring($_POST['chatcommands']) . '",
                        trustscore = "' . escapestring($_POST['trustscore']) . '",
                        tswarn = "' . escapestring($_POST['warnpoints']) . '",
                        tskick = "' . escapestring($_POST['kickpoints']) . '",
                        tsban = "' . escapestring($_POST['banpoints']) . '",
                        tscommend = "' . escapestring($_POST['commendpoints']) . '",
                        tstime = "' . escapestring($_POST['timepoints']) . '",
                        recent_time = "' . escapestring($_POST['recentplayers']) . '",
                        checktimeout = "' . escapestring($_POST['checktimeout']) . '",
                        permissions = \'' . escapestring(serialize(json_decode($_POST['permissions']))) . '\',
                        serveractions = \'' . escapestring(serialize(json_decode($_POST['serveractions']))) . '\'
                         WHERE community="' . userCommunity($_SESSION['steamid']) . '"', false);                    
                         
                        $temppermissions = json_decode($_POST['permissions'], JSON_PRETTY_PRINT);
                        if(array_keys($temppermissions)[0] != "owner") {
                            dbquery('UPDATE users SET rank="' . escapestring(array_keys($temppermissions)[0]) . '" WHERE steamid="' . $_SESSION['steamid'] . '"');
                        }

                        echo json_encode(array('success' => true, 'reload' => true));
                    }
                    break;
                case "delserver":
                    if (!hasPermission($_SESSION['steamid'], 'editservers')) {
                        echo json_encode(array('message' => 'You do not have permission to edit servers!'));
                        exit();
                    }
                    if ($request->param('serverid') != null) {
                        dbquery('DELETE FROM servers WHERE ID="' . escapestring($request->param('serverid')) . '" AND community="' . userCommunity($_SESSION['steamid']) . '"', false);
                        echo json_encode(array('success' => true, 'reload' => true));
                    }
                    break;
                case "delcommunity":
                    if ($request->param('securitycheck') != null) {
                        if(strtolower($request->param('securitycheck')) == "i wish to delete my community") {
                            dbquery('UPDATE communities SET owner="deleted_' . escapestring($_SESSION['steamid']) . '" WHERE uniqueid="' . userCommunity($_SESSION['steamid']) . '"', false);
                            dbquery('UPDATE users SET rank="user", community="" WHERE steamid="' . escapestring($_SESSION['steamid']) . '"', false);
                            echo json_encode(array('success' => true, 'reload' => true));                            
                        } else {
                            echo json_encode(array('success' => false, 'message' => 'Please type "I wish to delete my community" in the text-box above.'));
                        }
                    } else {
                        echo json_encode(array('success' => false, 'message' => 'Please type "I wish to delete my community" in the text-box above.'));
                    }
                    break;
                    //
                case "addstaff":
                    if (!hasPermission($_SESSION['steamid'], 'editstaff')) {
                        echo json_encode(array('message' => 'You do not have permission to edit staff!'));
                        exit();
                    }
                    if ($request->param('steamid') == null || $request->param('rank') == null) {
                        echo json_encode(array('message' => 'Please fill in all of the fields!'));
                    } else {
                        dbquery('UPDATE users SET rank="' . escapestring($request->param('rank')) . '", community="' . userCommunity($_SESSION['steamid']) . '" WHERE steamid="' . escapestring($request->param('steamid')) . '"', false);
                        echo json_encode(array('success' => true, 'reload' => true));
                    }
                    break;
                case "delstaff":
                    if (!hasPermission($_SESSION['steamid'], 'editstaff')) {
                        echo json_encode(array('message' => 'You do not have permission to edit staff!'));
                        exit();
                    }
                    if ($request->param('steamid') != null) {
                        dbquery('UPDATE users SET rank="user", community="" WHERE steamid="' . escapestring($request->param('steamid')) . '"', false);
                        echo json_encode(array('success' => true, 'reload' => true));
                    }
                    break;
                case "delwarn":
                    if (!hasPermission($_SESSION['steamid'], 'delrecord')) {
                        echo json_encode(array('message' => 'You do not have permission to remove a record!'));
                        exit();
                    }
                    dbquery('DELETE FROM warnings WHERE ID="' . escapestring($request->param('warnid')) . '" AND community="' . userCommunity($_SESSION['steamid']) . '"', false);
                    echo json_encode(array('success' => true, 'reload' => true));
                    break;
                case "delcommend":
                    if (!hasPermission($_SESSION['steamid'], 'delrecord')) {
                        echo json_encode(array('message' => 'You do not have permission to remove a record!'));
                        exit();
                    }
                    dbquery('DELETE FROM commend WHERE ID="' . escapestring($request->param('commendid')) . '" AND community="' . userCommunity($_SESSION['steamid']) . '"', false);
                    echo json_encode(array('success' => true, 'reload' => true));
                    break;
                case "delnote":
                    if (!hasPermission($_SESSION['steamid'], 'delrecord')) {
                        echo json_encode(array('message' => 'You do not have permission to remove a record!'));
                        exit();
                    }
                    dbquery('DELETE FROM notes WHERE ID="' . escapestring($request->param('noteid')) . '" AND community="' . userCommunity($_SESSION['steamid']) . '"', false);
                    echo json_encode(array('success' => true, 'reload' => true));
                    break;
                case "delkick":
                    if (!hasPermission($_SESSION['steamid'], 'delrecord')) {
                        echo json_encode(array('message' => 'You do not have permission to remove a record!'));
                        exit();
                    }
                    dbquery('DELETE FROM kicks WHERE ID="' . escapestring($request->param('kickid')) . '" AND community="' . userCommunity($_SESSION['steamid']) . '"', false);
                    echo json_encode(array('success' => true, 'reload' => true));
                    break;
                case "delban":
                    if (!hasPermission($_SESSION['steamid'], 'delrecord')) {
                        echo json_encode(array('message' => 'You do not have permission to remove a record!'));
                        exit();
                    }
                    dbquery('DELETE FROM bans WHERE ID="' . escapestring($request->param('banid')) . '" AND community="' . userCommunity($_SESSION['steamid']) . '"', false);
                    echo json_encode(array('success' => true, 'reload' => true));
                    break;
            }
        } else {
            echo json_encode(array("response" => "403", "message" => "User rank does not have access to POST API."));
        }
    } else {
        echo json_encode(array("response" => "401", "message" => "Unauthenticated API request."));
    }

});


$klein->onHttpError(function ($code, $router) {
    $service = $router->service();
    $service->render('app/pages/404.php', array('community' => siteConfig('community_name'), 'title' => $code . ' Error'));
});

$klein->dispatch();
