// Скрипт webhook-медіатипу Zabbix: передає сповіщення в alert.php бота.
// Параметри медіатипу (Name -> Value):
//   url                 https://<host>/alert.php
//   token               <alerts.token з config.php>
//   sendto              {ALERT.SENDTO}
//   subject             {ALERT.SUBJECT}
//   message             {ALERT.MESSAGE}
//   event_id            {EVENT.ID}
//   event_value         {EVENT.VALUE}
//   event_update_status {EVENT.UPDATE.STATUS}
//   parse_mode          (необов'язково) html | markdown | markdownv2
try {
    var params = JSON.parse(value);
    var req = new HttpRequest();
    req.addHeader('Content-Type: application/json');
    req.addHeader('X-Alert-Token: ' + params.token);

    var body = {
        sendto: params.sendto,
        subject: params.subject,
        message: params.message,
        event_id: params.event_id,
        event_value: params.event_value,
        event_update_status: params.event_update_status,
        parse_mode: params.parse_mode || ''
    };
    var resp = req.post(params.url, JSON.stringify(body));

    if (req.getStatus() !== 200) {
        throw 'HTTP ' + req.getStatus() + ': ' + resp;
    }
    return 'OK: ' + resp;
} catch (error) {
    Zabbix.log(3, '[ZabbixBot alert] ' + error);
    throw 'Sending failed: ' + error;
}
