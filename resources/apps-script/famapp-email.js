/**
 * Forwards FamApp "You received ₹X" e-mails from THIS Gmail account to your UPI gateway
 * (gateway account #__ACCOUNT_ID__). Nothing is sent except those e-mails, signed with a
 * secret only you and the gateway know. Your Gmail password never leaves Google.
 *
 * Setup: paste this whole file into https://script.google.com (New project), press Save,
 * choose the function "install" and press Run, then approve the permissions once.
 */
const ENDPOINT = '__ENDPOINT__';
const SECRET = '__SECRET__';
const SENDER = '__SENDER__';

/** Run once: checks the mailbox every minute from now on. */
function install() {
  ScriptApp.getProjectTriggers().forEach(function (t) { ScriptApp.deleteTrigger(t); });
  ScriptApp.newTrigger('forwardFamAppEmails').timeBased().everyMinutes(1).create();
  forwardFamAppEmails();
}

function forwardFamAppEmails() {
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(1000)) return;
  try {
    const props = PropertiesService.getScriptProperties();
    const done = JSON.parse(props.getProperty('done') || '{}');
    const cutoff = Date.now() - 3 * 24 * 3600 * 1000;
    Object.keys(done).forEach(function (id) { if (done[id] < cutoff) delete done[id]; });

    GmailApp.search('from:' + SENDER + ' subject:"You received" newer_than:2d', 0, 30).forEach(function (thread) {
      thread.getMessages().forEach(function (msg) {
        if (done[msg.getId()]) return;
        if (msg.getFrom().toLowerCase().indexOf(SENDER) === -1) return;
        if (send_(msg)) done[msg.getId()] = Date.now();   // otherwise retried next minute
      });
    });

    props.setProperty('done', JSON.stringify(done));
  } finally {
    lock.releaseLock();
  }
}

function send_(msg) {
  const payload = JSON.stringify({
    message_id: msg.getId(),
    from: msg.getFrom(),
    subject: msg.getSubject(),
    body: msg.getPlainBody().substring(0, 15000),
    received_at: msg.getDate().toISOString(),
    auth_results: authResults_(msg.getRawContent())
  });
  const timestamp = String(Math.floor(Date.now() / 1000));
  const response = UrlFetchApp.fetch(ENDPOINT, {
    method: 'post',
    contentType: 'application/json',
    payload: payload,
    headers: { 'X-Timestamp': timestamp, 'X-Signature': sign_(timestamp + '.' + payload) },
    muteHttpExceptions: true
  });
  const code = response.getResponseCode();
  return code >= 200 && code < 300;
}

/**
 * Gmail's own SPF/DKIM/DMARC verdict. Gmail stamps its header first, so the first
 * "Authentication-Results: mx.google.com" is the trustworthy one; copies injected by a
 * sender come later and are ignored.
 */
function authResults_(raw) {
  const head = raw.split(/\r?\n\r?\n/)[0].replace(/\r?\n[ \t]+/g, ' ');
  const lines = head.split(/\r?\n/);
  for (var i = 0; i < lines.length; i++) {
    const m = lines[i].match(/^authentication-results:\s*(mx\.google\.com\b.*)$/i);
    if (m) return m[1];
  }
  return '';
}

function sign_(value) {
  return Utilities.computeHmacSha256Signature(value, SECRET)
    .map(function (b) { return ('0' + (b < 0 ? b + 256 : b).toString(16)).slice(-2); })
    .join('');
}
