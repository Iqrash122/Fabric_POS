// Use the installed browser; do not extract browser downloads through the
// currently unpatched extract-zip transitive dependency during npm install.
module.exports = { skipDownload: true };
