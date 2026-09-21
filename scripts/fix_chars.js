const fs = require('fs');
const path = require('path');

const dir = 'c:/xampp/htdocs/EINSTEIN-WEB18';
const files = fs.readdirSync(dir).filter(f => f.endsWith('.html'));

files.forEach(f => {
  const fp = path.join(dir, f);
  let c = fs.readFileSync(fp, 'utf8');
  
  // Fix diamond replacement character (U+FFFD or actual mojibake for ₱)
  // The '?' in the files are already ₱ from previous fix, but let's also handle:
  
  // Fix separator diamonds: ◆ -> •  (these appear between items like "Saturdays ◆ Aug to Jan")
  c = c.replace(/◆/g, '•');
  
  // Also fix the \u0007 (BEL character) used as bullet
  c = c.replace(/\u0007/g, '•');
  
  // Fix any remaining U+FFFD replacement character
  c = c.replace(/\uFFFD/g, '•');
  
  fs.writeFileSync(fp, c, 'utf8');
  console.log('Fixed: ' + f);
});

console.log('Done!');
