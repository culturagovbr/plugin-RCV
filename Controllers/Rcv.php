<?php

namespace RCV\Controllers;

use MapasCulturais\API;
use MapasCulturais\App;
use MapasCulturais\ApiQuery;
use MapasCulturais\Controller;
use PhpOffice\PhpSpreadsheet\IOFactory;
use MapasCulturais\Entities\Registration;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;



class Rcv extends Controller
{
    function __construct() {}

    public function GET_relatorio()
    {
        $app = App::i();

          //Seta o timeout
          ini_set('max_execution_time', 0);
          ini_set('memory_limit', '1024M');   

        $this->requireAuthentication();

        if (!$app->user->is('admin')) {
            $app->pass();
        }

        $cenarios = $app->config['rcv.scenarios'];
        $app->view->enqueueStyle('app-v2', 'rcv-relatorio', 'css/rcv-relatorio.css');
        $cenarios = $this->data['cenario'] ?? null;
        $this->render("relatorio", ['cenarios' => $cenarios]);
    }

    public function GET_getdata()
    {
        $app = App::i();

        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '1024M'); 

        $this->requireAuthentication();

        if (!$app->user->is('admin')) {
            $app->pass();
        }

        $page = isset($this->data['page']) ? (int)$this->data['page'] : 1;
        $limit = isset($this->data['limit']) ? (int)$this->data['limit'] : 20;
        
        $page = max(1, $page);
        $limit = max(1, min(50, $limit));

        $result = [];
        $panab_opportunity_id = $app->config['rcv.pnabOpportunityId'];
        $pnab_attachment_id = 'rfc_' . $app->config['rcv.pnabOpportunityAttachmentId'];
        $scenarios = $this->data['cenario'] ?? null;
        $only_errors = isset($this->data['apenas_erros']) && $this->data['apenas_erros'] == '1';
        $show_all = isset($this->data['mostrar_tudo']) && $this->data['mostrar_tudo'] == '1';

        if (is_array($scenarios)) {
            $scenarios_array = array_map(fn($v) => 'Cenário ' . trim($v), $scenarios);
        } elseif (is_string($scenarios) && !empty($scenarios)) {
            $scenarios_array = array_map(fn($v) => 'Cenário ' . trim($v), explode(',', $scenarios));
        } else {
            $scenarios_array = null;
        }

        $query = new ApiQuery("MapasCulturais\Entities\Registration", [
            '@select' => "id",
            '@page' => $page,
            '@limit' => $limit,
            '@order' => 'id ASC',
            'status' => API::EQ(Registration::STATUS_APPROVED),
            'opportunity' => API::EQ($panab_opportunity_id)
        ]);

        $count_query = new ApiQuery("MapasCulturais\Entities\Registration", [
            '@select' => "id",
            'status' => API::EQ(Registration::STATUS_APPROVED),
            'opportunity' => API::EQ($panab_opportunity_id)
        ]);
        $total_registrations = $count_query->getCountResult();

        $all_columns = [];
        $valid_spreadsheets = [];

        if ($registrations = $query->getFindResult()) {
            foreach ($registrations as $registration) {
                $registration = $app->repo('Registration')->findOneBy(['id' => $registration['id']]);
                $spreadsheet_path = $registration->files[$pnab_attachment_id]->path ?? null;

                if ($spreadsheet_path && file_exists($spreadsheet_path)) {
                    $spreadsheet_data = $this->getSpreadsheetData($spreadsheet_path);
                    if ($spreadsheet_data) {
                        $valid_spreadsheets[$registration->id] = $spreadsheet_data;
                        $columns = array_keys(reset($spreadsheet_data));
                        $all_columns = array_unique(array_merge($all_columns, $columns));
                    }
                }
            }

            if (empty($all_columns)) {
                $all_columns = ['Inscrição PNAB', 'Cenário'];
            }

            foreach ($registrations as $registration) {
                $registration = $app->repo('Registration')->findOneBy(['id' => $registration['id']]);
                
                $log_file_path = PUBLIC_PATH . 'files/importer/' . $registration->id . '.log';
                $spreadsheet_path = $registration->files[$pnab_attachment_id]->path ?? null;
                $spreadsheet_data = $valid_spreadsheets[$registration->id] ?? null;

                $has_error = false;
                $error_message = '';

                if (!$spreadsheet_path) {
                    $has_error = true;
                    $error_message = 'Planilha não encontrada';
                } elseif (!file_exists($log_file_path) || filesize($log_file_path) == 0) {
                    $has_error = true;
                    $error_message = !file_exists($log_file_path) 
                        ? 'Arquivo de log não encontrado' 
                        : 'Arquivo de log vazio';
                } elseif (!$spreadsheet_data) {
                    $has_error = true;
                    $error_message = 'Erro ao processar planilha (arquivo vazio ou corrompido)';
                } elseif ($this->hasPhpErrors($log_file_path)) {
                    $has_error = true;
                    $has_success_completion = $this->hasSuccessCompletion($log_file_path);
                    $error_message = $has_success_completion 
                        ? 'Processamento concluído mas existem erros então não foi contabilizada'
                        : 'Existem erros de processamento nessa inscrição';
                    $app->log->warning("PHP errors detected in log file: {$log_file_path}");
                }

                if ($has_error) {
                    if ($only_errors || $show_all) {
                        $result[] = $this->createErrorRow($all_columns, $registration->number, $error_message);
                    }
                    continue;
                }

                if ($only_errors) {
                    continue;
                }

                if ($log_lines = $this->getLogLines($log_file_path)) {
                    if ($scenarios_array) {
                        $filtered_lines = array_filter($log_lines, function ($line) use ($scenarios_array) {
                            foreach ($scenarios_array as $scenario) {
                                if (strpos($line, $scenario) !== false) {
                                        return true;
                                    }
                                }
                                return false;
                            });
                        } else {
                        $filtered_lines = $log_lines;
                        }
                       
                    foreach ($filtered_lines as $line) {
                            if (preg_match('/\[(\d+)\/\d+\]/', $line, $matches)) {
                            $row = (int) $matches[1] + 1;

                            $scenario_found = null;
                            if (preg_match('/Cenário\s+(\d+)/i', $line, $scenario_matches)) {
                                $scenario_found = 'Cenário ' . $scenario_matches[1];
                            }

                            if (isset($spreadsheet_data[$row])) {
                                $row_data = $spreadsheet_data[$row];
                                $row_data['Inscrição PNAB'] = $registration->number;
                                $row_data['Cenário'] = $scenario_found;
                                $result[] = $row_data;
                            }
                        }                       
                    }
                }
            }
        }

        $this->json([
            'data' => $result,
            'metadata' => [
                'page' => $page,
                'limit' => $limit,
                'total_registrations' => $total_registrations,
                'total_pages' => $limit > 0 ? ceil($total_registrations / $limit) : 0,
                'registrations_in_page' => count($registrations ?? []),
                'results_in_page' => count($result),
                'info' => 'Paginação baseada em inscrições. Cada página processa ' . $limit . ' inscrições e pode gerar número variável de resultados.'
            ]
        ]);
    }

    private function getSpreadsheetsMap(string $dir): array
    {
        $files = scandir($dir);
        $map = [];

        foreach ($files as $file) {
            if (in_array($file, [".", ".."])) {
                continue;
            }

            $clean_name = trim(preg_replace('/ - [a-f0-9]+ - /i', ' - ', $file));
            $map[pathinfo($clean_name, PATHINFO_FILENAME)] = $file;
        }

        return $map;
    }

    private function getSpreadsheetData(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, true);

        if (empty($data)) {
            return [];
        }

        $headers = array_map(function ($v) {
            if ($v === null) return null;
            return trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $v)));
        }, $data[1]);

        $headers = array_filter($headers, fn($v) => !empty($v));

        $result = [];

        foreach ($data as $line_num => $line) {
            if ($line_num === 1) {
                continue;
            }

            $record = [];
            foreach ($headers as $col => $header_name) {
                $record[$header_name] = $line[$col] ?? null;
            }

            if (array_filter($record)) {
                $result[$line_num] = $record;
            }
        }

        return $result;
    }

    private function getLogLines(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $content = file_get_contents($path);
        $lines = $content ? explode("\n", trim($content)) : [];
        
        return $this->filterPhpErrors($lines);
    }

    private function filterPhpErrors(array $lines): array
    {
        $filtered = [];
        $php_error_patterns = [
            '/^(PHP )?(Fatal error|Warning|Notice|Parse error|Deprecated|Strict Standards):/i',
            '/^Stack trace:/i',
            '/^#\d+\s+/',
            '/^\s+thrown in\s+/i',
            '/^Exception:/i',
            '/^Error:/i',
        ];

        foreach ($lines as $line) {
            $is_php_error = false;
            
            foreach ($php_error_patterns as $pattern) {
                if (preg_match($pattern, trim($line))) {
                    $is_php_error = true;
                    break;
                }
            }
            
            if (!$is_php_error) {
                $filtered[] = $line;
            }
        }

        return $filtered;
    }

    private function hasPhpErrors(string $log_file_path): bool
    {
        if (!file_exists($log_file_path)) {
            return false;
        }

        $content = file_get_contents($log_file_path);
        $php_error_patterns = [
            '/Fatal error:/i',
            '/Warning:/i',
            '/Notice:/i',
            '/Parse error:/i',
            '/Exception:/i',
        ];

        foreach ($php_error_patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    private function hasSuccessCompletion(string $log_file_path): bool
    {
        if (!file_exists($log_file_path)) {
            return false;
        }

        $content = file_get_contents($log_file_path);
        return strpos($content, 'Importação concluída com sucesso') !== false;
    }

    private function createErrorRow(array $columns, string $registration_number, string $error_message): array
    {
        $row_data = array_fill_keys($columns, '');
        $row_data['Inscrição PNAB'] = $registration_number;
        $row_data['Cenário'] = $error_message;
        return $row_data;
    }


    public function GET_exportExcel()
    {
        $app = App::i();
        $result = [];

        $panab_opportunity_id = $app->config['rcv.pnabOpportunityId'];
        $pnab_attachment_id = 'rfc_' . $app->config['rcv.pnabOpportunityAttachmentId'];
        $scenarios = $this->data['cenario'] ?? null;
        $only_errors = isset($this->data['apenas_erros']) && $this->data['apenas_erros'] == '1';
        $show_all = isset($this->data['mostrar_tudo']) && $this->data['mostrar_tudo'] == '1';

        if (is_array($scenarios)) {
            $scenarios_array = array_map(fn($v) => 'Cenário ' . trim($v), $scenarios);
        } elseif (is_string($scenarios) && !empty($scenarios)) {
            $scenarios_array = array_map(fn($v) => 'Cenário ' . trim($v), explode(',', $scenarios));
        } else {
            $scenarios_array = null;
        }

        $query = new ApiQuery("MapasCulturais\Entities\Registration", [
            '@select' => "id",
            'status' => API::EQ(Registration::STATUS_APPROVED),
            'opportunity' => API::EQ($panab_opportunity_id)
        ]);

        $all_columns = [];
        $valid_spreadsheets = [];

        if ($registrations = $query->getFindResult()) {
            foreach ($registrations as $registration) {
                $registration = $app->repo('Registration')->findOneBy(['id' => $registration['id']]);
                $spreadsheet_path = $registration->files[$pnab_attachment_id]->path ?? null;

                if ($spreadsheet_path && file_exists($spreadsheet_path)) {
                    $spreadsheet_data = $this->getSpreadsheetData($spreadsheet_path);
                    if ($spreadsheet_data) {
                        $valid_spreadsheets[$registration->id] = $spreadsheet_data;
                        $columns = array_keys(reset($spreadsheet_data));
                        $all_columns = array_unique(array_merge($all_columns, $columns));
                    }
                }
            }

            if (empty($all_columns)) {
                $all_columns = ['Inscrição PNAB', 'Cenário'];
            }

            foreach ($registrations as $registration) {
                $registration = $app->repo('Registration')->findOneBy(['id' => $registration['id']]);

                $log_file_path = PUBLIC_PATH . 'files/importer/' . $registration->id . '.log';
                $spreadsheet_path = $registration->files[$pnab_attachment_id]->path ?? null;
                $spreadsheet_data = $valid_spreadsheets[$registration->id] ?? null;

                $has_error = false;
                $error_message = '';

                if (!$spreadsheet_path) {
                    $has_error = true;
                    $error_message = 'Planilha não encontrada';
                } elseif (!file_exists($log_file_path) || filesize($log_file_path) == 0) {
                    $has_error = true;
                    $error_message = !file_exists($log_file_path) 
                        ? 'Arquivo de log não encontrado' 
                        : 'Arquivo de log vazio';
                } elseif (!$spreadsheet_data) {
                    $has_error = true;
                    $error_message = 'Erro ao processar planilha (arquivo vazio ou corrompido)';
                } elseif ($this->hasPhpErrors($log_file_path)) {
                    $has_error = true;
                    $has_success_completion = $this->hasSuccessCompletion($log_file_path);
                    $error_message = $has_success_completion 
                        ? 'Processamento concluído mas existem erros então não foi contabilizada'
                        : 'Existem erros de processamento nessa inscrição';
                    $app->log->warning("PHP errors detected in log file: {$log_file_path}");
                }

                if ($has_error) {
                    if ($only_errors || $show_all) {
                        $result[] = $this->createErrorRow($all_columns, $registration->number, $error_message);
                    }
                    continue;
                }

                if ($only_errors) {
                    continue;
                }

                if ($log_lines = $this->getLogLines($log_file_path)) {
                    if ($scenarios_array) {
                        $filtered_lines = array_filter($log_lines, function ($line) use ($scenarios_array) {
                            foreach ($scenarios_array as $scenario) {
                                if (strpos($line, $scenario) !== false) {
                                    return true;
                                }
                            }
                            return false;
                        });
                    } else {
                        $filtered_lines = $log_lines;
                    }

                    foreach ($filtered_lines as $line) {
                        if (preg_match('/\[(\d+)\/\d+\]/', $line, $matches)) {
                            $row = (int) $matches[1] + 1;

                            $scenario_found = null;
                            if (preg_match('/Cenário\s+(\d+\.?\d*)/i', $line, $scenario_matches)) {
                                $scenario_found = 'Cenário ' . $scenario_matches[1];
                            }

                            if (isset($spreadsheet_data[$row])) {
                                $row_data = $spreadsheet_data[$row];
                                $row_data['Inscrição PNAB'] = $registration->number;
                                $row_data['Cenário'] = $scenario_found == 'Cenário 3.2' ? 'Cenário 4' : $scenario_found;
                                $result[] = $row_data;
                            }
                        }
                    }
                }
            }
        }

        if (empty($result)) {
            echo "Nenhum dado disponível para exportar.";
            return;
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = array_keys($result[0]);
        $column_letter = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($column_letter . '1', $header);
            $column_letter++;
        }

        $last_column = chr(ord('A') + count($headers) - 1);
        $header_style = [
            'font' => ['bold' => true, 'color' => ['rgb' => '212529']],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F1F3F5']
            ],
            'borders' => [
                'bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
            ]
        ];
        $sheet->getStyle('A1:' . $last_column . '1')->applyFromArray($header_style);

        $row_num = 2;
        foreach ($result as $data) {
            $column_letter = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($column_letter . $row_num, $data[$header] ?? '');
                $column_letter++;
            }
            $row_num++;
        }

        foreach (range('A', $last_column) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'relatorio_pnab_' . date('Y-m-d_H-i-s') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

}
