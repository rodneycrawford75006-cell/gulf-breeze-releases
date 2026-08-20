from pathlib import Path
from pypdf import PdfReader

for language, path, expected in (
    ("English", Path("/tmp/gb-cert-en.pdf"), ("Student instructions", "90 days")),
    ("Spanish", Path("/tmp/gb-cert-es.pdf"), ("Instrucciones para el estudiante", "90 días")),
):
    assert path.is_file() and path.stat().st_size > 100_000
    reader = PdfReader(path)
    assert len(reader.pages) == 3
    assert not (reader.get_fields() or {})
    assert all(
        annotation.get_object().get("/Subtype") != "/Widget"
        for page in reader.pages
        for annotation in (page.get("/Annots") or [])
    )
    text = "\n".join((page.extract_text() or "") for page in reader.pages)
    assert "ADEE9" in text
    assert "TEST - NOT VALID FOR DPS OR TDLR" in text
    assert all(value in text for value in expected)
    print(f"PASS {language} issued PDF: 3 static pages, populated ADEE mock number, watermark, localized instructions")
