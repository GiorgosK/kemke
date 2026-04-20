-- Make Markdown inline code visually obvious in DOCX output.
-- Pandoc already maps `text` to the Word "Verbatim Char" style; wrapping it in
-- Strong keeps the monospace style and adds bold emphasis for UI labels.
function Code(el)
  return pandoc.Strong({el})
end
