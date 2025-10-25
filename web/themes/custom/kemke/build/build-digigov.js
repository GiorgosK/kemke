#!/usr/bin/env node

/**
 * Generate a browser-friendly bundle of the Digigov Tailwind exports.
 *
 * The upstream distribution ships CommonJS modules (module.exports = {...})
 * intended for Node/Tailwind builds. Drupal expects plain browser scripts,
 * so this script serializes the objects and exposes them on window.Digigov.
 */

const fs = require('fs');
const path = require('path');

const themeRoot = path.resolve(__dirname, '..');
const digigovDir = path.join(themeRoot, 'digigov');
const outputDir = path.join(digigovDir, 'dist');

const files = [
  { name: 'base', globalKey: 'base' },
  { name: 'components', globalKey: 'components' },
  { name: 'utilities', globalKey: 'utilities' }
];

function loadModule(fileName) {
  const modulePath = path.join(digigovDir, `${fileName}.js`);
  delete require.cache[require.resolve(modulePath)];
  return require(modulePath);
}

function ensureOutputDir() {
  if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
  }
}

function buildBundle() {
  ensureOutputDir();

  const payload = files.reduce((acc, file) => {
    acc[file.globalKey] = loadModule(file.name);
    return acc;
  }, {});

  const banner = `/**
 * Auto-generated file. Run "npm run build:digigov" to rebuild.
 */`;

  const wrapper = `${banner}
(function (global) {
  if (!global) {
    return;
  }
  var namespace = global.Digigov = global.Digigov || {};
  namespace.css = ${JSON.stringify(payload, null, 2)};
})(typeof window !== 'undefined' ? window : (typeof self !== 'undefined' ? self : null));
`;

  const outputPath = path.join(outputDir, 'digigov.globals.js');
  fs.writeFileSync(outputPath, wrapper);
  console.log(`Created ${path.relative(themeRoot, outputPath)}`);
}

buildBundle();
