// Prevent right-click context menu
document.addEventListener('contextmenu', function(e) {
    e.preventDefault();
});

// Prevent common DevTools keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // F12
    if (e.key === 'F12' || e.keyCode === 123) {
        e.preventDefault();
        return false;
    }
    
    // Ctrl+Shift+I (Windows/Linux) or Cmd+Opt+I (Mac) - Inspector
    if ((e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'i')) || 
        (e.metaKey && e.altKey && (e.key === 'I' || e.key === 'i'))) {
        e.preventDefault();
        return false;
    }
    
    // Ctrl+Shift+J (Windows/Linux) or Cmd+Opt+J (Mac) - Console
    if ((e.ctrlKey && e.shiftKey && (e.key === 'J' || e.key === 'j')) || 
        (e.metaKey && e.altKey && (e.key === 'J' || e.key === 'j'))) {
        e.preventDefault();
        return false;
    }
    
    // Ctrl+Shift+C (Windows/Linux) or Cmd+Opt+C (Mac) - Element Inspector
    if ((e.ctrlKey && e.shiftKey && (e.key === 'C' || e.key === 'c')) || 
        (e.metaKey && e.altKey && (e.key === 'C' || e.key === 'c'))) {
        e.preventDefault();
        return false;
    }
    
    // Ctrl+U (Windows/Linux) or Cmd+U (Mac) - View Source
    if ((e.ctrlKey && (e.key === 'U' || e.key === 'u')) || 
        (e.metaKey && (e.key === 'U' || e.key === 'u'))) {
        e.preventDefault();
        return false;
    }
});
