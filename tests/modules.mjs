// Checks the manager's browser modules without a browser and without running them: every file parses, every import
// names a file that exists, every imported name is exported there, and every file is reachable from main.js. The
// browser would otherwise report such a mistake only when the page loads.
//
//   node --experimental-vm-modules tests/modules.mjs

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const root = path.resolve(import.meta.dirname, '../resources/assets/manager');
const failures = [];
const modules = new Map();

function load(file) {
  if (!modules.has(file)) {
    modules.set(file, new vm.SourceTextModule(fs.readFileSync(file, 'utf8'), { identifier: file }));
  }
  return modules.get(file);
}

const show = (file) => path.relative(root, file).replaceAll('\\', '/');

try {
  // linking resolves every import and checks the imported names; nothing is evaluated
  await load(path.join(root, 'main.js')).link((specifier, from) => {
    if (!specifier.startsWith('./') && !specifier.startsWith('../')) {
      throw new Error(`${show(from.identifier)} imports '${specifier}': only relative paths work without a build`);
    }
    const file = path.resolve(path.dirname(from.identifier), specifier);
    if (!fs.existsSync(file)) {
      throw new Error(`${show(from.identifier)} imports '${specifier}', which does not exist`);
    }
    return load(file);
  });
} catch (e) {
  failures.push(e.message);
}

const all = fs.readdirSync(root, { recursive: true }).filter((f) => f.endsWith('.js'));
for (const file of all) {
  if (!modules.has(path.join(root, file))) failures.push(`${file.replaceAll('\\', '/')} is not imported by any module`);
}

if (failures.length) {
  console.log(failures.map((f) => 'FAIL ' + f).join('\n'));
  process.exit(1);
}
console.log(`modules: ${all.length} files, all imports resolve`);
