<?php

namespace App\Services\Imports;

use App\Enums\UserType;
use App\Models\Academic\Group;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Plantillas de carga masiva, una por rol, en CSV y en XLSX.
 *
 * Cada rol pide solo lo que necesita:
 *  - Estudiante: nombre, correo y sección (obligatorios) más sus datos de ficha.
 *  - Docente y administrador: nombre y correo. No tienen ficha propia; las
 *    materias y grupos del docente se asignan después desde Gestión académica.
 * Nadie lleva contraseña: cada usuario recibe un correo para crearla.
 *
 * El CSV usa `;` porque es lo que Excel espera con configuración regional en
 * español. BulkFileReader detecta el separador y sigue aceptando las columnas
 * y cabeceras en inglés de las plantillas antiguas.
 */
class BulkTemplateService
{
    public const CSV_DELIMITER = ';';

    /** Roles con carga masiva. */
    public const ROLES = ['student', 'teacher', 'admin'];

    /**
     * Definición de cada plantilla. En `columns`, el `*` marca las
     * obligatorias; el lector lo ignora al interpretar la cabecera.
     */
    private const TEMPLATES = [
        'student' => [
            'sheet'    => 'Estudiantes',
            'title'    => 'Carga masiva de estudiantes',
            'filename' => 'plantilla_estudiantes',
            // «ID de usuario» no va: solo sirve para actualizar estudiantes
            // existentes y confundía en una plantilla de alta (el importador
            // lo sigue aceptando).
            'columns'  => [
                'full_name'       => 'Nombre completo *',
                'email'           => 'Correo institucional *',
                // La sección («6-1») y no el código del aula: es lo que conoce
                // quien arma el archivo. El importador sigue aceptando «Aula».
                'seccion'         => 'Sección *',
                'student_code'    => 'Código de estudiante',
                'status'          => 'Estado',
                'birth_date'      => 'Fecha de nacimiento',
                'parent_name'     => 'Nombre del tutor',
                'parent_email'    => 'Correo del tutor',
                'adecuacion_type' => 'Tipo de adecuación',
            ],
        ],
        'teacher' => [
            'sheet'    => 'Docentes',
            'title'    => 'Carga masiva de docentes',
            'filename' => 'plantilla_docentes',
            'columns'  => [
                'full_name' => 'Nombre completo *',
                'email'     => 'Correo institucional *',
            ],
        ],
        'admin' => [
            'sheet'    => 'Administradores',
            'title'    => 'Carga masiva de administradores',
            'filename' => 'plantilla_administradores',
            'columns'  => [
                'full_name' => 'Nombre completo *',
                'email'     => 'Correo institucional *',
            ],
        ],
    ];

    /**
     * Cabeceras en español aceptadas al subir, incluidas las columnas
     * opcionales que ya no salen en la plantilla pero el importador procesa.
     */
    private const HEADER_ALIASES = [
        'nombre_completo'      => 'full_name',
        'nombre'               => 'full_name',
        'correo_institucional' => 'email',
        'correo'               => 'email',
        'aula'                 => 'aula',
        'seccion'              => 'seccion',
        'id_de_usuario'        => 'user_id',
        'codigo_de_estudiante' => 'student_code',
        'estado'               => 'status',
        'fecha_de_nacimiento'  => 'birth_date',
        'nombre_del_tutor'     => 'parent_name',
        'correo_del_tutor'     => 'parent_email',
        'tipo_de_adecuacion'   => 'adecuacion_type',
    ];

    /** Estado en español → valor de StudentStatus. */
    public const STATUS_LABELS = [
        'activo'     => 'active',
        'inactivo'   => 'inactive',
        'suspendido' => 'suspended',
    ];

    /** Opciones del desplegable de adecuación (el importador ignora la tilde). */
    private const ADECUACION_OPTIONS = ['acceso', 'contenido', 'evaluación'];

    /** Filas preparadas (bordes y desplegables) en la hoja de datos. */
    private const XLSX_DATA_ROWS = 300;

    /** Fila de la cabecera en el XLSX: encima van el título y la descripción. */
    private const XLSX_HEADER_ROW = 4;

    // Paleta del sistema (tema de Ant Design en AdminLayout)
    private const COLOR_PRIMARY = '2563EB';
    private const COLOR_TEXT    = '111827';
    private const COLOR_MUTED   = '6B7280';
    private const COLOR_BORDER  = 'E5E7EB';
    private const FONT          = 'Segoe UI';

    /**
     * Clave interna para una cabecera del archivo, en español o en inglés.
     * Ignora mayúsculas, tildes, espacios y el `*`: «Código de estudiante» y
     * «student_code» dan lo mismo. Una cabecera desconocida se devuelve
     * normalizada (y el importador la ignora).
     */
    public static function columnForHeader(string $header): string
    {
        $normalized = trim(preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii(trim($header)))), '_');

        return self::HEADER_ALIASES[$normalized] ?? $normalized;
    }

    /** Valor de StudentStatus para un estado escrito en español o en inglés. */
    public static function statusValue(string $input): string
    {
        $value = Str::lower(Str::ascii(trim($input)));

        return self::STATUS_LABELS[$value] ?? $value;
    }

    public function download(string $role, string $format): StreamedResponse
    {
        return $format === 'xlsx' ? $this->xlsx($role) : $this->csv($role);
    }

    public function csv(string $role): StreamedResponse
    {
        $template = self::TEMPLATES[$role];
        $filename = $template['filename'] . '.csv';

        return response()->streamDownload(function () use ($template) {
            // BOM UTF-8 para que Excel en Windows respete las tildes
            echo "\xEF\xBB\xBF" . implode(self::CSV_DELIMITER, $template['columns']) . "\r\n";
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function xlsx(string $role): StreamedResponse
    {
        $filename = self::TEMPLATES[$role]['filename'] . '.xlsx';

        // Secciones de las aulas de la institución (TenantScoped): alimentan el desplegable.
        $secciones = $role === UserType::Student->value
            ? Group::query()->whereNotNull('section')->where('section', '!=', '')->orderBy('grade')->orderBy('section')
                ->pluck('section')->unique()->values()->all()
            : [];

        return response()->streamDownload(function () use ($role, $secciones) {
            $book = $this->buildWorkbook($role, $secciones);

            (new XlsxWriter($book))->save('php://output');

            // Octane: liberar las referencias cruzadas entre hojas.
            $book->disconnectWorksheets();
            unset($book);
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** @param array<int,string> $secciones */
    public function buildWorkbook(string $role, array $secciones = []): Spreadsheet
    {
        $template = self::TEMPLATES[$role];
        $columns  = $template['columns'];

        $book = new Spreadsheet();
        $book->getProperties()->setCreator('NeoEduCore')->setTitle($template['title']);
        $book->getDefaultStyle()->getFont()->setName(self::FONT)->setSize(10)->getColor()->setRGB(self::COLOR_TEXT);

        $sheet = $book->getActiveSheet();
        $sheet->setTitle($template['sheet']);
        $sheet->setShowGridlines(false);

        $headerRow = self::XLSX_HEADER_ROW;
        $firstData = $headerRow + 1;
        $lastRow   = $headerRow + self::XLSX_DATA_ROWS;
        $lastCol   = chr(ord('A') + count($columns) - 1);

        // Título y descripción
        $sheet->setCellValue('A1', $template['title']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getRowDimension(1)->setRowHeight(26);

        $sheet->setCellValue('A2', 'Completa una fila por persona. Los campos con * son obligatorios. Cada usuario recibirá un correo para crear su contraseña.');
        $sheet->getStyle('A2')->getFont()->getColor()->setRGB(self::COLOR_MUTED);

        // Cabecera, anchos y formato por columna
        $letras = [];
        $col    = 'A';
        foreach ($columns as $key => $label) {
            $letras[$key] = $col;
            $sheet->setCellValueExplicit("{$col}{$headerRow}", $label, DataType::TYPE_STRING);
            $sheet->getColumnDimension($col)->setWidth(match ($key) {
                'full_name', 'email', 'parent_name', 'parent_email' => 32,
                'seccion', 'status'                                 => 14,
                default                                             => 22,
            });
            // Texto en todo salvo la fecha: evita que Excel convierta códigos
            // como 0012 en números.
            $sheet->getStyle("{$col}{$firstData}:{$col}{$lastRow}")->getNumberFormat()
                ->setFormatCode($key === 'birth_date' ? 'yyyy-mm-dd' : '@');
            $col++;
        }

        $header = $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}");
        $header->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_PRIMARY);
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
        $sheet->getRowDimension($headerRow)->setRowHeight(24);

        // Cuerpo: bordes suaves
        $body = $sheet->getStyle("A{$firstData}:{$lastCol}{$lastRow}");
        $body->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
        $body->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);
        for ($row = $firstData; $row <= $lastRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(20);
        }

        $sheet->freezePane("A{$firstData}");
        $sheet->setSelectedCell("A{$firstData}");

        if ($role === UserType::Student->value) {
            $this->addStudentDropdowns($book, $sheet, $letras, $firstData, $lastRow, $secciones);
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * Desplegables de aula, estado y adecuación. Las opciones van en una hoja
     * oculta: una lista inline tiene tope de 255 caracteres y no alcanzaría
     * para muchas aulas.
     *
     * @param array<string,string> $letras
     * @param array<int,string>    $secciones
     */
    private function addStudentDropdowns(Spreadsheet $book, Worksheet $sheet, array $letras, int $firstData, int $lastRow, array $secciones): void
    {
        $listas = $book->createSheet();
        $listas->setTitle('Listas');
        $listas->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $opciones = [
            'seccion'         => [array_values($secciones), 'Elige una sección de la lista.'],
            'status'          => [array_keys(self::STATUS_LABELS), 'Elige activo, inactivo o suspendido.'],
            'adecuacion_type' => [self::ADECUACION_OPTIONS, 'Elige un tipo de la lista o deja la celda vacía.'],
        ];

        $listCol = 'A';
        foreach ($opciones as $key => [$valores, $error]) {
            if ($valores !== []) {
                foreach ($valores as $i => $valor) {
                    $listas->setCellValueExplicit("{$listCol}" . ($i + 1), $valor, DataType::TYPE_STRING);
                }

                $validation = new DataValidation();
                $validation->setType(DataValidation::TYPE_LIST)
                    ->setFormula1("Listas!\${$listCol}\$1:\${$listCol}\$" . count($valores))
                    ->setAllowBlank(true)
                    ->setShowDropDown(true)
                    ->setShowErrorMessage(true)
                    ->setErrorTitle('Valor no válido')
                    ->setError($error);
                $sheet->setDataValidation("{$letras[$key]}{$firstData}:{$letras[$key]}{$lastRow}", $validation);
            }
            $listCol++;
        }
    }
}
