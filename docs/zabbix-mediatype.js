// Скрипт webhook-медіатипу Zabbix: передає сповіщення в alert.php бота.
// Медіатип цілком (параметри, шаблони) генерує `php console.php app:mediatype export|install`,
// цей файл - джерело його скрипта (MediaTypeDefinition).
//
// Службові параметри: url (https://<host>/alert.php), token (alerts.token), http_proxy (необов'язково).
// Решта параметрів пересилаються в alert.php як є: sendto/subject/message/event_* тощо. Бот формує текст сам,
// мовою отримувача; subject/message (шаблони медіатипу) - запасний варіант.
// Макрос, який у цьому контексті не розкрився (напр. {EVENT.RECOVERY.DATE} у проблемі) або *UNKNOWN*,
// передається порожнім рядком.
var SERVICE = ['url', 'token', 'http_proxy'];

function escapeMarkup(str, mode) {
    switch (mode) {
        case 'markdown':
            return str.replace(/([_*\[`])/g, '\\$&');
        case 'markdownv2':
            return str.replace(/([_*\[\]()~`>#+\-=|{}.!])/g, '\\$&');
        case 'html':
            // теги шаблону лишаються, "<" з даних (напр. "a < b" у назві) - ні
            return str.replace(/<(\s|[^a-z\/])/g, '&lt;$1');
        default:
            return str;
    }
}

try {
    var params = JSON.parse(value),
        body = {},
        req = new HttpRequest(),
        resp;

    Object.keys(params).forEach(function (key) {
        if (SERVICE.indexOf(key) !== -1) {
            return;
        }
        var v = params[key];
        if (typeof v === 'string' && (v === '*UNKNOWN*' || /^\{(\?[\s\S]*|[A-Z][A-Z0-9_.]*)\}$/.test(v))) {
            v = '';
        }
        body[key] = v;
    });

    var mode = (body.parse_mode || '').toLowerCase();
    if (['markdown', 'markdownv2', 'html'].indexOf(mode) !== -1) {
        body.subject = escapeMarkup(body.subject || '', mode);
        body.message = escapeMarkup(body.message || '', mode);
    }

    if (params.http_proxy) {
        req.setProxy(params.http_proxy);
    }
    req.addHeader('Content-Type: application/json');
    req.addHeader('X-Alert-Token: ' + params.token);
    resp = req.post(params.url, JSON.stringify(body));

    if (req.getStatus() !== 200) {
        throw 'HTTP ' + req.getStatus() + ': ' + resp;
    }
    return 'OK: ' + resp;
} catch (error) {
    Zabbix.log(3, '[ZabbixBot alert] ' + error);
    throw 'Sending failed: ' + error;
}
