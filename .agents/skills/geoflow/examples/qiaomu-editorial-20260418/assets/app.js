const PACKAGE_META = Object.freeze([
  { text: 'Qiaomu Editorial', accent: true },
  { text: 'reference: sanitized local example', accent: false },
  { text: 'MVP: homepage → article', accent: false }
]);

function loadPackageMeta() {
  const mount = document.getElementById('packageMeta');
  if (!mount) return;
  for (const item of PACKAGE_META) {
    const chip = document.createElement('div');
    chip.className = item.accent ? 'chip chip--accent' : 'chip';
    chip.textContent = item.text;
    mount.appendChild(chip);
  }
}
window.addEventListener('DOMContentLoaded', loadPackageMeta);
