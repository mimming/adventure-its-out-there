<?php
/*
 * Copyright (C) 2013 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
// Author: Jenny Murphy - http://google.com/+JennyMurphy


// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] != "POST") {
  header("HTTP/1.0 405 Method not supported");
  echo("Method not supported");
  exit();
}

// Always respond with a 200 right away and then terminate the connection to prevent notification
// retries. How this is done depends on your HTTP server configs. I'll try a few common techniques
// here, but if none of these work, start troubleshooting here.

// First try: the content length header
header("Content-length: 0");

// Next, assuming it didn't work, attempt to close the output buffer by setting the time limit.
ignore_user_abort(true);
set_time_limit(0);

// And one more thing to try: forking the heavy lifting into a new process. Yeah, crazy eh?
if (function_exists('pcntl_fork')) {
  $pid = pcntl_fork();
  if ($pid == -1) {
    error_log("could not fork!");
    exit();
  } else if ($pid) {
    // fork worked! but I'm the parent. time to exit.
    exit();
  }
}

// In the child process (hopefully). Do the processing.
require_once 'config.php';
require_once 'mirror-client.php';
require_once 'google-api-php-client/src/Google_Client.php';
require_once 'google-api-php-client/src/contrib/Google_MirrorService.php';
require_once 'util.php';

// Parse the request body
$request_bytes = @file_get_contents('php://input');
$request = json_decode($request_bytes, true);

// A notification has come in. If there's an attached photo, bounce it back
// to the user
$user_id = $request['userToken'];

$access_token = get_credentials($user_id);

$client = get_google_api_client();
$client->setAccessToken($access_token);

// A glass service for interacting with the Mirror API
$mirror_service = new Google_MirrorService($client);

switch ($request['collection']) {
  case 'timeline':
    // Verify that it's a share
    foreach ($request['userActions'] as $i => $user_action) {
      if ($user_action['type'] == 'SHARE') {

        $timeline_item_id = $request['itemId'];

        $timeline_item = $mirror_service->timeline->get($timeline_item_id);

        // Patch the item. Notice that since we retrieved the entire item above
        // in order to access the caption, we could have just changed the text
        // in place and used the update method, but we wanted to illustrate the
        // patch method here.
        $patch = new Google_TimelineItem();
        $patch->setText("PHP Quick Start got your photo! " .
            $timeline_item->getText());
        $mirror_service->timeline->patch($timeline_item_id, $patch);
        break;
      }
    }

    break;
  case 'locations':
    $location = $mirror_service->locations->get("latest");

    // Get an xola feed for near us
    $xolaFeed = file_get_contents("https://dev.xola.com/api/experiences?geo=".$location->getLatitude().",".$location->getLongitude().",100");
    $xolaStuff = json_decode($xolaFeed);
    $lat = $xolaStuff->data[0]->geo->lat;
    $lon = $xolaStuff->data[0]->geo->lon;
    $category =  $xolaStuff->data[0]->category;
    $photo = $xolaStuff->data[0]->photo->src;

    $excerpt = $xolaStuff->data[0]->excerpt;
    $desc = $xolaStuff->data[0]->desc;

    $price = $xolaStuff->data[0]->price;
    $date = $xolaStuff->data[0]->schedules[0]->dates[0];
    $name = $xolaStuff->data[0]->name;


    // Insert a new timeline card, with a copy of that photo attached
    $loc_timeline_item = new Google_TimelineItem();
    $loc_timeline_item->setHtml("<style>img {max-height:360px;}</style><article>".
    "<figure><img src='https://dev.xola.com$photo'></figure>".
    "<section><h1 class='text-large'>$name</h1><p class='text-x-small'>$category</p>".
    "<hr><p class='text-normal'>$date</p>".
    "</section></article>");
    $loc_timeline_item->

        insert_timeline_item($mirror_service, $loc_timeline_item, null, null);

    break;
  default:
    error_log("I don't know how to process this notification: $request");
}

