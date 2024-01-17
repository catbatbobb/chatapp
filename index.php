<?php

//initialization
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\CloseFrame;
use Swoole\Coroutine\Http\Server;
use function Swoole\Coroutine\run;


include "msgs.php";

function openFile($name) {
  ob_start();
  try {
    require $name;
  } catch (Exception $e) {
    echo $e;
  }
  $data = ob_get_contents();
  ob_end_clean();
  return $data;
};
function host($http, $path, $type) {
  $http->handle("/".$path, function ($request, $response) use ($type, $path) {
    $response->header('Content-Type', $type);
    $response->end(openFile("files/".$path));
  });
};
$sockets = array();

// the function below is what will be ran
run(function () use ($sockets) {
  function emitAll($data) {
    foreach ($GLOBALS['sockets'] as $socket) {
      //echo "emitting $data to $socket... \n";
      $socket->push($data);
    }
  };
  $deleteSocket = function ($socket) use ($sockets) {
    echo "deleted a socket \n";
    $index = array_search($socket, $GLOBALS['sockets']);
    array_splice($GLOBALS['sockets'], $index, 1);
  };
  $paths = array(
    "css/ripple.css" => "text/css; charset=utf-8",
    "css/style.css" => "text/css; charset=utf-8",
    "js/bg.js" => "application/javascript; charset=utf-8",
    "js/popup.js" => "application/javascript; charset=utf-8",
    "js/protect.js" => "application/javascript; charset=utf-8",
    "js/ripple.js" => "application/javascript; charset=utf-8",
    "js/auth.js" => "application/javascript; charset=utf-8",
    "js/filter.js" => "application/javascript; charset=utf-8",
    "js/styling.js" => "application/javascript; charset=utf-8",
    "js/userdata.js" => "application/javascript; charset=utf-8",
    "js/undrag.js" => "application/javascript; charset=utf-8",
    "images/favicon.webp" => "image/webp",
    "images/favicon.ico" => "image/x-icon",
    "images/favicon.svg" => "image/svg+xml",
    "html/404.html"=> "text/html; charset=utf-8"
  );

  $http = new Server('0.0.0.0', 8000);    // create new server

  $http->handle('/', function ($request, $response) {
    // handle page requests
    $headers = $request->header;
    if ($headers["x-replit-user-name"] && $headers["x-replit-user-id"]) {
      $response->header('Content-Type', 'text/html; charset=utf-8');
      $response->end(str_replace(
        "{{name}}", 
        $headers["x-replit-user-name"],
        openFile("templates/index.html")
      ));
      //$response->end("Hi");,
    } else {
      $response->header('Content-Type', 'text/html; charset=utf-8');
      $response->end("<script>window.location.href = 'login';</script>");
    }
  });

  $http->handle("/login", function ($request, $response) {
    $response->header('Content-Type', 'text/html; charset=utf-8');
    $response->end(openFile("templates/login.html"));
  });
  
  foreach($paths as $path => $type) {
    host($http, $path, $type);
  }
  
  $http->handle('/ws-server', function (Request $request, Response $ws) use ($sockets, $deleteSocket) {
    // upgrade to websocket
    echo "new websocket connected \n";
    $ws->upgrade();
    array_push($GLOBALS['sockets'], $ws);
    sendMsgs($ws);
    $ws->push(json_encode(array(
      "channel"=>"lastMsg"
    )));
    while (true) {
      $frame = $ws->recv();
      if ($frame === '') {
        // ???
        $ws->close();
        $deleteSocket($ws);
        echo "something happened, deleted socket\n";
        break;
      } else if ($frame === false) {
        // handle error
        echo 'errorCode: ' . swoole_last_error() . "\n";
        $ws->close();
        $deleteSocket($ws);
        break;
      } else {
        // checks for the closing signal
        if ($frame->data == 'close' || get_class($frame) === CloseFrame::class) {
          echo "manual close\n";
          $ws->close();
          $deleteSocket($ws);
          break;
        }
        // and finally send the data
        echo "received message \n";
        $msg = storeMsg($frame->data, $ws);
        if ($msg != null) emitAll($msg, $GLOBALS['sockets']);
      }
    }
  });

  print("server started\n");
  $http->start();

});
