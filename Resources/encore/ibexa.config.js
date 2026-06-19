const path = require('path');

module.exports = (Encore) => {
    Encore.addEntry('haeretici-admin', [
        path.resolve(__dirname, '../public/scss/admin/styles.scss'),
        path.resolve(__dirname, '../public/js/admin/script.js'),
    ]);
};