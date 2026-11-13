<?php
//
// zindello-cluster: the alert rules API (external-security.zindello.com.au/docs/alert-rules.md,
// "The API"; cluster-docs O431-O438), behind ZoneMinder's own sign-in (AppController::beforeFilter).
//
//   GET    alert_rules.json                   the caller's rules
//   GET    alert_rules/<user id>.json         that user's
//   PUT    alert_rules/<user id>.json         set any of: alerts (true/false), cameras
//                                             ({"<camera id>": {"alerts": true, "labels": ["person"]}}),
//                                             reset (true: back to the default); answers as GET
//   DELETE alert_rules/phone/<phone id>.json  remove a paired phone (its token_hash)
//
// Anyone their own; a user who may edit System settings, anyone's; otherwise 403. Unknown user,
// camera or phone 404; bad input 400. The rules and the checks are includes/alert_rules.php, shared
// with the Event Server page.
//
App::uses('AppController', 'Controller');

class AlertRulesController extends AppController {

  public $uses = array();
  public $components = array('RequestHandler');

  public function beforeFilter() {
    parent::beforeFilter();
    require_once __DIR__ .'/../../../includes/alert_rules.php';
    if (!alert_rules_caller()) {
      throw new ForbiddenException(__('Alert rules are per user, so they need ZoneMinder\'s sign-in'));
    }
    if (!alert_rules_ready()) {
      throw new HttpException(__('Alert rules aren\'t set up on this server'), 503);
    }
  }

  // The user named, once the caller may see them: 403 before 404, so nobody learns who exists.
  private function target($id) {
    $id = ($id === null) ? alert_rules_caller()->Id() : $id;
    if (!preg_match('/^[0-9]+$/', (string)$id)) throw new BadRequestException(__('Invalid user'));
    if (!alert_rules_may_manage(intval($id))) throw new ForbiddenException(__('Insufficient Privileges'));
    $target = alert_rules_user(intval($id));
    if (!$target) throw new NotFoundException(__('Invalid user'));
    return $target;
  }

  private function phoneJson($p) {
    $iso = function($s) {
      $t = alert_rules_utc($s);
      return $t === null ? null : gmdate('Y-m-d\TH:i:s\Z', $t);
    };
    return array(
      'id' => $p['token_hash'],
      'platform' => $p['platform'],
      'app_version' => $p['app_version'],
      'name' => $p['profile'],
      'paired' => $iso($p['first_seen']),
      'last_connected' => $iso($p['last_seen']),
      'last_alert' => $iso($p['last_push']),
      'removing' => (bool)$p['remove'],
    );
  }

  private function answer($target) {
    $rules = alert_rules_get($target);
    $rules['cameras'] = (object)$rules['cameras'];
    $rules['phones'] = array_map(array($this, 'phoneJson'), alert_rules_phones($target->Username()));
    if (alert_rules_is_admin()) {
      $rules['unowned_phones'] = array_map(array($this, 'phoneJson'), alert_rules_unowned_phones());
    }
    $this->set(array(
      'alert_rules' => $rules,
      '_serialize' => array('alert_rules')
    ));
  }

  public function index() {
    $this->request->allowMethod('get');
    $this->answer($this->target(null));
  }

  public function view($id = null) {
    $this->request->allowMethod('get');
    $this->answer($this->target($id));
  }

  public function edit($id = null) {
    $this->request->allowMethod('put');
    $target = $this->target($id);
    $data = $this->request->data;
    if (!is_array($data)) throw new BadRequestException(__('Nothing to change'));
    $change = array_intersect_key($data, array('alerts' => 1, 'cameras' => 1, 'reset' => 1));
    // A form can't send an empty list: cameras[<id>][labels]= is one.
    if (isset($change['cameras']) and is_array($change['cameras'])) {
      foreach ($change['cameras'] as $mid => $c) {
        if (is_array($c) and isset($c['labels']) and $c['labels'] === '') $change['cameras'][$mid]['labels'] = array();
      }
    }
    if (!count($change)) throw new BadRequestException(__('Set any of alerts, cameras or reset'));
    list($status, $message) = alert_rules_change($target, $change);
    if ($status == 400) throw new BadRequestException($message);
    if ($status == 404) throw new NotFoundException($message);
    if ($status != 200) throw new InternalErrorException($message);
    $this->answer($target);
  }

  public function remove_phone($id = null) {
    $this->request->allowMethod('delete');
    $phone = alert_rules_phone($id);
    if (!$phone) throw new NotFoundException(__('Invalid phone'));
    if (!alert_rules_may_remove_phone($phone)) throw new ForbiddenException(__('Insufficient Privileges'));
    if (!alert_rules_remove_phone($phone)) throw new InternalErrorException(__('The phone couldn\'t be removed'));
    $this->set(array(
      'message' => 'Removed. The event server forgets the phone within about 30 seconds.',
      '_serialize' => array('message')
    ));
  }
}
