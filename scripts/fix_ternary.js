/**
 * Fix script: Restore ternary '?' operators in JavaScript code that were incorrectly
 * replaced with '•' by the previous character-fix script.
 * 
 * Strategy: For each file, parse line by line.
 * If a line is inside a <script> tag, replace ' • ' back to ' ? ' ONLY where it looks like a ternary:
 *   - pattern: <expression> • <expression> : <expression>
 * Outside script tags (in HTML), keep '•' as bullet separators.
 */
const fs = require('fs');
const path = require('path');

const dir = 'c:/xampp/htdocs/EINSTEIN-WEB18';
const htmlFiles = fs.readdirSync(dir).filter(f => f.endsWith('.html'));
const jsFiles = fs.readdirSync(dir).filter(f => f.endsWith('.js'));

function fixJS(content) {
  // In JS content, ' • ' used as a separator in ternary context should be ' ? '
  // Ternary pattern: something • something : something
  // But we only want to fix ternaries, not true bullet separators like "One Tutor • Two Children"
  
  // Replace ' ? ' with ' ? ' ONLY when followed eventually by ' : ' on same line (ternary)
  // Also fix patterns like: variable • value
  const lines = content.split('\n');
  return lines.map(line => {
    // If line contains ' ? ' and also contains ' : ' (ternary), restore ternary
    if (line.includes(' ? ') && line.includes(' : ')) {
      // Check if it looks like JavaScript (not an HTML content line)
      // Heuristic: line contains JS-like tokens: =>, =, (, ), ;, ||, &&, etc.
      const looksLikeJS = /[;{}()\[\]]|=>|&&|\|\||\bconst\b|\blet\b|\bvar\b|\breturn\b|\bif\b|\bfunction\b/.test(line);
      if (looksLikeJS) {
        // Replace ' • ' with ' ? ' only if it's part of a ternary pattern
        // Ternary: expr • trueExpr : falseExpr
        line = line.replace(/ • /g, ' ? ');
      }
    }
    // Also fix cases where bullet was put in data values inside JS strings like 'Group • ?2,500'
    // These should stay as ' • ' (they're display strings) - already handled above since they lack ternary ':'
    return line;
  }).join('\n');
}

function fixHTMLFile(filePath) {
  let content = fs.readFileSync(filePath, 'utf8');
  const lines = content.split('\n');
  let inScript = false;
  const fixed = lines.map(line => {
    const lower = line.toLowerCase();
    if (!inScript && lower.includes('<script')) inScript = true;
    if (inScript && lower.includes('</script')) {
      inScript = false;
      return line; // process the closing script tag as plain HTML
    }
    if (inScript) {
      // Inside script: fix ternary operators
      if (line.includes(' ? ') && line.includes(' : ')) {
        const looksLikeJS = /[;{}()\[\]]|=>|&&|\|\||\bconst\b|\blet\b|\bvar\b|\breturn\b|\bif\b|\bfunction\b/.test(line);
        if (looksLikeJS) {
          line = line.replace(/ • /g, ' ? ');
        }
      }
      // Fix cases without ':' but clearly ternary-like
      if (line.includes(' ? ') && !line.includes(' : ')) {
        // Check for patterns like: variable • value (without quotes around it)
        // e.g. "e.created_at • new Date" - clearly broken JS
        if (/\w+ • \w/.test(line) && /[;{}()\[\]]|=>|&&|\|\||\bconst\b|\blet\b|\bvar\b|\breturn\b|\bif\b|\bfunction\b|\bnew\b|\bthis\b/.test(line)) {
          line = line.replace(/ • /g, ' ? ');
        }
      }
    }
    return line;
  });
  
  const result = fixed.join('\n');
  fs.writeFileSync(filePath, result, 'utf8');
  console.log('Fixed: ' + path.basename(filePath));
}

function fixJSFile(filePath) {
  let content = fs.readFileSync(filePath, 'utf8');
  const result = fixJS(content);
  fs.writeFileSync(filePath, result, 'utf8');
  console.log('Fixed JS: ' + path.basename(filePath));
}

htmlFiles.forEach(f => fixHTMLFile(path.join(dir, f)));
jsFiles.forEach(f => fixJSFile(path.join(dir, f)));

console.log('All done!');
