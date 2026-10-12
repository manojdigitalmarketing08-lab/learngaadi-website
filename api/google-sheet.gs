/**
 * Google Sheet lead log.
 * 1. Create a Google Sheet, then Extensions > Apps Script and paste this file.
 * 2. Set SECRET below (any long random text) and use the same value as sheet_secret in config.php.
 * 3. Deploy > New deployment > Web app > Execute as: Me, Who has access: Anyone. Copy the /exec URL
 *    into sheet_webhook_url in config.php.
 */
const SECRET = 'CHANGE_ME';
const SHEET_NAME = 'Leads';
const HEADERS = ['Time', 'ID', 'Name', 'Phone', 'Service', 'Page', 'Source', 'Medium', 'Campaign',
                 'Referrer', 'Landing page', 'Click ID', 'IP'];

function doPost(e) {
  try {
    const data = JSON.parse(e.postData.contents);
    if (data.secret !== SECRET) return out({ ok: false, error: 'forbidden' });
    const ss = SpreadsheetApp.getActiveSpreadsheet();
    const sh = ss.getSheetByName(SHEET_NAME) || ss.insertSheet(SHEET_NAME);
    if (sh.getLastRow() === 0) {
      sh.appendRow(HEADERS);
      sh.setFrozenRows(1);
    }
    const l = data.lead;
    sh.appendRow([new Date(), data.id, l.name, "'" + l.phone, l.service, l.page_url, l.source, l.medium,
                  l.campaign, l.referrer, l.landing_page, l.click_id, l.ip_address]);
    return out({ ok: true });
  } catch (err) {
    return out({ ok: false, error: String(err) });
  }
}

function out(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
