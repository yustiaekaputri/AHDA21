"""Only fictional CI data. Create a minimal, valid OOXML workbook using stdlib."""
import csv
import sys
from pathlib import Path
from xml.sax.saxutils import escape
from zipfile import ZipFile, ZIP_DEFLATED

root = Path(sys.argv[1])
root.mkdir(parents=True, exist_ok=False)
header = ["npsn", "nama", "jenjang", "provinsi", "kabupaten", "kecamatan", "alamat"]
rows = [
    ["TEST0003", "SD IMPOR DEMO FIKTIF", "SD", "Provinsi Fiktif A", "Kabupaten Fiktif A", "Kecamatan Fiktif A", ""],
    ["TEST0004", "SD FILTER DEMO FIKTIF", "SD", "Provinsi Fiktif B", "Kabupaten Fiktif B", "Kecamatan Fiktif B", ""],
    ["bad", "INVALID DEMO FIKTIF", "SD", "Provinsi Fiktif A", "Kabupaten Fiktif A", "Kecamatan Fiktif A", ""],
]
for name, data in [("batch.csv", rows), ("update.csv", [["TEST0003", "SD IMPOR DEMO FIKTIF", "SD", "Provinsi Fiktif A", "Kabupaten Fiktif A", "Kecamatan Fiktif A", "Alamat Demo Baru"]])]:
    with (root / name).open("w", encoding="utf-8", newline="") as f:
        csv.writer(f).writerows([header, *data])
ns = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
rel = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
pkg = "http://schemas.openxmlformats.org/package/2006/relationships"
types = "http://schemas.openxmlformats.org/package/2006/content-types"
for bad in (False, True):
    body = []
    for idx, row in enumerate([header, ["TEST0005", "SMP XLSX DEMO FIKTIF", "SMP", "Provinsi Fiktif A", "Kabupaten Fiktif A", "Kecamatan Fiktif A", "Alamat Fiktif"]], 1):
        cells = []
        for col, v in enumerate(row):
            ref = chr(65 + col) + str(idx)
            if bad and idx == 2 and col == 1:
                cells.append(f'<c r="{ref}"><f>1+1</f><v>2</v></c>')
            else:
                cells.append(f'<c r="{ref}" t="inlineStr"><is><t>{escape(v)}</t></is></c>')
        body.append(f'<row r="{idx}">' + "".join(cells) + "</row>")
    with ZipFile(root / ("formula.xlsx" if bad else "valid.xlsx"), "w", ZIP_DEFLATED) as z:
        z.writestr("[Content_Types].xml", f'<Types xmlns="{types}"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>')
        z.writestr("_rels/.rels", f'<Relationships xmlns="{pkg}"><Relationship Id="rId1" Type="{rel}/officeDocument" Target="xl/workbook.xml"/></Relationships>')
        z.writestr("xl/workbook.xml", f'<workbook xmlns="{ns}" xmlns:r="{rel}"><sheets><sheet name="Demo" sheetId="1" r:id="rId1"/></sheets></workbook>')
        z.writestr("xl/_rels/workbook.xml.rels", f'<Relationships xmlns="{pkg}"><Relationship Id="rId1" Type="{rel}/worksheet" Target="worksheets/sheet1.xml"/></Relationships>')
        z.writestr("xl/worksheets/sheet1.xml", f'<worksheet xmlns="{ns}"><sheetData>{"".join(body)}</sheetData></worksheet>')
