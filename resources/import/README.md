# CSV Import (Products)

## Template
Use the template file:

- `resources/import/templates/products_import_template.csv`

## Create a timestamped import file
Copy the template into the runtime import folder:

```bash
TS=$(date +"%Y%m%d_%H%M")
mkdir -p var/import/incoming
cp resources/import/templates/products_import_template.csv "var/import/incoming/products_${TS}.csv"
