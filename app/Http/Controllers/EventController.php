<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\User;
use App\Event;

class EventController extends Controller
{
  //first step is to create the authorization request
  public function auth()
  {
    // Initialise the client.
    $client = new \Google_Client();

    $client->setApplicationName('Calendar integration');
    $client->setAuthConfig(storage_path('app/credentials.json'));
    $client->setAccessType("offline");
    $client->setIncludeGrantedScopes(true);
    $client->setApprovalPrompt('force');
    $client->addScope(\Google_Service_Calendar::CALENDAR);
    //'http://localhost:8080/oauth2callback.php'
    $client->setRedirectUri(\URL::to('/') . '/oauth2callback');
    //先導向讓user同意授權的網址(登入Google帳戶並同意授權)
    $authUrl = $client->createAuthUrl();
    return redirect($authUrl);
  }

  //Handle the OAuth 2.0 server response
  public function oauth2callback(Request $request)
  {
    $client = new \Google_Client();
    $client->setAuthConfig(storage_path('app/credentials.json'));

    $client->authenticate($_GET['code']);

    $accessToken = $client->getAccessToken();
    session(['accessToken' => $accessToken]);

    return redirect('/show');
    // (session('accessToken'));  //最後頁面為 印出token
  }

  public function getToken()
  {
    $client = new \Google_Client();
    //成為授權的client 有權力CRUD
    $client->setAccessToken(session('accessToken'));
    //如果Token過期了 就Refresh Token 然後存回去session
    if ($client->isAccessTokenExpired()) {
      $accessToken = $client->fetchAccessTokenWithAuthCode($client->getRefreshToken());
      session(['accessToken' => $accessToken]);
    }

    return $client;
  }


  public function show()
  {
      //get dates in current month/year
      $year = date('Y');
      $month = date('m');

      $days = cal_days_in_month(CAL_GREGORIAN, $month, $year);

      $firstDateOfTheMonth = new \DateTime("$year-$month-1");

      $lastDateOfTheMonth = new \DateTime("$year-$month-$days");

      $frontPadding = $firstDateOfTheMonth->format('w');  //0-6
      $backPadding = 6 - $lastDateOfTheMonth->format('w');

      for ($i=0; $i < $frontPadding; $i++) {    //填前面的padiing
          $dates[] = null;
      }
      for ($i=0; $i < $days; $i++) {           //填1~31
          $dates[] = $i + 1;
      }
      for ($i=0; $i < $backPadding; $i++) {     //填後面的padiing
          $dates[] = null;
      }

      //get user's events
      $client = $this->getToken();
      $events = $this->getEvents($client);

      return view('/index', ['events' => $events, 'dates' => $dates]);
  }

  public function getEvents($client)
  {
    // $client = $this->getToken(); //執行完後就會變成 授權的client物件
    $service = new \Google_Service_Calendar($client);

    $optParams = array(
    'orderBy' => 'startTime',
    'singleEvents' => true,
    'timeMin' => '2020-04-01T00:00:00+08:00',
    'timeMax' => '2020-04-30T23:59:59+08:00',
    );
    $eventsG = $service->events->listEvents('primary', $optParams);
    $events =[];
    // while(true) {
    foreach ($eventsG->getItems() as $key => $event) {
      //id
      $events[$key]['id'] = $event->getId();
      //title
      $events[$key]['title'] = $event->getSummary();

      //date, start_time
      $start = $event->getStart();
      //01~09 -> 1~9
      if (substr($start['dateTime'], 8, 1) == '0')
          $events[$key]['date'] = substr($start['dateTime'], 9, 1);
      else
          $events[$key]['date'] = substr($start['dateTime'], 8, 2);

      $events[$key]['start_time'] = substr($start['dateTime'], 11, 5);
      //end_time
      $end = $event->getEnd();
      $events[$key]['end_time'] = substr($end['dateTime'], 11, 5);
      //description
      $events[$key]['description'] = $event->getDescription();
    }
      //   $pageToken = $events->getNextPageToken();
      //   if ($pageToken) {
      //     $optParams = array('pageToken' => $pageToken);
      //     $eventsG = $service->events->listEvents('primary', $optParams);
      //   } else {
      //     break;
      //   }
      // }
      return $events;
  }

  public function read($request)
  {
    $client = $this->getToken();
    $service = new \Google_Service_Calendar($client);
    $eventG = $service->events->get('primary', $request);

    $event['id'] = $eventG->getId();
    $event['title'] = $eventG->getSummary();
    $start = $eventG->getStart();
    $event['start_time']= substr($start['dateTime'], 11, 5);;
    $end = $eventG->getEnd();
    $event['end_time'] = substr($end['dateTime'], 11, 5);
    $event['description']= $eventG->getDescription();

    return response()->json($event);
  }

  public function store(Request $request)
  {
      //Title
      if ($this->eventTitleValidate($request))
        return response()->json('Title caonnot be blank.', 404);
      //Time range
      if ($this->eventTimeValidate($request))
        return response()->json('Time range error.', 404);

      $client = $this->getToken();
      $service = new \Google_Service_Calendar($client);

      $event = $this->get_event($request);

      $eventG = $this->get_eventG($event, $request);

      $calendarId = 'primary';
      $eventG = $service->events->insert($calendarId, $eventG);
      $event['id']= $eventG->getId();

      return response()->json($event);
  }

  public function update(Request $request)
  {
      //Title
      if ($this->eventTitleValidate($request))
        return response()->json('Title caonnot be blank.', 404);
      //Time range
      if ($this->eventTimeValidate($request))
        return response()->json('Time range error.', 404);

      $client = $this->getToken();
      $service = new \Google_Service_Calendar($client);

      $event = $this->get_event($request);
      $event['id'] = $request->input('id');

      $eventG = $this->get_eventG($event, $request);

      $service->events->update('primary', $event['id'] , $eventG);

      return response()->json($event);
  }

  public function get_event($request)
  {
    $event = [];
    $event['date'] =  $request->input('date');
    $event['title'] = $request->input('title');
    $event['description'] = $request->input('description');
    $event['start_time'] = $request->input('start_time');
    $event['end_time'] = $request->input('end_time');
    return $event;
  }

  public function get_eventG($event, $request)
  {
    $Start_dateTime = '2020-04-'.$event['date'].'T'.$event['start_time'].':00';
    $End_dateTime = '2020-04-'.$event['date'].'T'.$event['end_time'].':00';

    $eventG = new \Google_Service_Calendar_Event(array(
        'summary' => $event['title'],
        'location' => $request->input('location'),
        'description' => $event['description'],
        'start' => array(
        'dateTime' => $Start_dateTime,
        'timeZone' => "Asia/Taipei",
        ),
        'end' => array(
          'dateTime' => $End_dateTime,
          'timeZone' => "Asia/Taipei",
        ),
      ));
      return $eventG;
  }

  public function destroy(Request $request)
  {
    $client = $this->getToken();
    $service = new \Google_Service_Calendar($client);

    $eventId = $request->input('id');
    $event = $service->events->delete('primary', $eventId);

    return response()->json($event);
  }

  public function eventTitleValidate($request)
  {
    if (empty($request->input('title')))
      return true;
  }

  public function eventTimeValidate($request)
  {
    $startTime= explode(':', $request->input('start_time'));
    $endTime = explode(':', $request->input('end_time'));
    if ($startTime[0] > $endTime[0] || ($startTime[0]==$endTime[0] && $startTime[1]>$endTime[1])) {
      return true;
    }
  }

}
