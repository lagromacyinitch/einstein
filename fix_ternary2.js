/**
 * Fix script v2: More aggressive fix for broken ternary operators in script blocks.
 * Handles multi-line ternary patterns that were missed by the previous pass.
 */
const fs = require('fs');
const path = require('path');

const dir = 'c:/xampp/htdocs/EINSTEIN-WEB18';
const files = fs.readdirSync(dir).filter(f => f.endsWith('.html'));
const jsFiles = fs.readdirSync(dir).filter(f => f.endsWith('.js'));

function fixScriptContent(scriptContent) {
  const lines = scriptContent.split('\n');
  return lines.map(line => {
    // Replace ALL ' • ' in script context back to ' ? '
    // Exception: inside string literals that are clearly display text.
    // But it's safer to just replace all, since HTML bullet chars in JS strings
    // would be written as &bull; or Unicode escape anyway.
    
    // Only exception: lines that are clearly template literal content with bullet separators
    // like: `One Tutor • Two Children` — but these still need '?' not '•'
    // Actually, in JS code ' • ' was NEVER intended — only '?' ternary was.
    // True bullet separators in JS are in string values and use '•' intentionally.
    
    // So: replace ' • ' with ' ? ' if it appears at statement/expression level:
    // Heuristic: if NOT preceded by opening quote (content string context)
    
    // Simple approach: replace ' • ' to ' ? ' on lines that contain JS-like syntax markers
    if (line.includes(' • ')) {
      // Check for lines that are clearly code (not inside HTML template literals)
      // These patterns indicate real JS, not string content:
      const isCodeLine = /^\s*(const|let|var|return|function|if|else|while|for|switch|case|=>|\}|\{|\/\/)/.test(line)
        || /[;{}]$/.test(line.trim())
        || / • `/.test(line)  // ternary before template literal
        || / • '/.test(line)  // ternary before string
        || /\w • (?:\w|`|'|"|\[|\()/.test(line); // identifier • something
      
      if (isCodeLine) {
        line = line.replace(/ • /g, ' ? ');
      }
    }
    return line;
  }).join('\n');
}

function fixHTMLFile(filePath) {
  let content = fs.readFileSync(filePath, 'utf8');
  // Split on <script and </script> boundaries
  const parts = content.split(/(<script[^>]*>|<\/script>)/i);
  let inScript = false;
  const fixed = parts.map(part => {
    if (/^<script/i.test(part)) { inScript = true; return part; }
    if (/^<\/script>/i.test(part)) { inScript = false; return part; }
    if (inScript) return fixScriptContent(part);
    return part;
  });
  fs.writeFileSync(filePath, fixed.join(''), 'utf8');
  console.log('Fixed: ' + path.basename(filePath));
}

function fixJSFile(filePath) {
  let content = fs.readFileSync(filePath, 'utf8');
  const result = fixScriptContent(content);
  fs.writeFileSync(filePath, result, 'utf8');
  console.log('Fixed JS: ' + path.basename(filePath));
}

files.forEach(f => fixHTMLFile(path.join(dir, f)));
jsFiles.filter(f => !f.startsWith('fix_')).forEach(f => fixJSFile(path.join(dir, f)));
console.log('All done!');
