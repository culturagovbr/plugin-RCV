<?php

use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Registration;

use function Psy\debug;

$config = include THEMES_PATH . 'CulturaViva/conf-base.php';

return [
    'RCV - vincula coletivo e preenche category das inscrições importadas' => function () {
        return true;
        $app = App::i();
        /** @var Connection */
        $conn = $app->em->getConnection();

        $opportunity_id = 5386;

        DB_UPDATE::enqueue('Registration', "opportunity_id = $opportunity_id", function (Registration $registration) use ($app, $conn) {
            $reg_num = str_pad($registration->number, 20);

            $user_meta = $conn->fetchAssoc("SELECT * FROM user_meta WHERE object_id = {$registration->owner->user->id} AND key = 'redeCulturaViva'");
            if (is_string($user_meta['value'] ?? null)) {
                $user_meta = (object) json_decode($user_meta['value']);
            }

            if (!$user_meta) {
                $app->log->error("$reg_num - Erro ao tentar vincular coletivo e preencher category da inscrição para $registration");
                return;
            }

            // vincula o coletivo à inscrição;
            if ($registration->owner->id == $user_meta->agenteIndividual) {
                $agent_id = $user_meta->agentePonto;

                if (!$conn->fetchScalar("SELECT id FROM agent WHERE id = '$agent_id'")) {
                    if ($registration->status == 0) {
                        $app->log->debug("$reg_num - REMOVE INSCRIÇÃO EM RASCUNHO SEM AGENTE COLETIVO");
                        $conn->delete('registration', ['id' => $registration->id]);
                        return;
                    } else {
                        $app->log->debug("$reg_num - INSCRIÇÃO COM STATUS = '$registration->status' SEM AGENTE COLETIVO - não faz nada");
                        return;
                    }
                }

                $app->log->debug("$reg_num - INSERINDO RELAÇÃO COM AGENTE COLETIVO $agent_id");
                $conn->insert('agent_relation', [
                    'agent_id' => $agent_id,
                    'object_id' => $registration->id,
                    'object_type' => Registration::class,
                    'type' => 'coletivo',
                    'status' => 1,
                    'metadata' => '{}'
                ]);
            } else {
                return;
            }

            $coletivo_tipo_ponto = $conn->fetchScalar("SELECT value FROM agent_meta WHERE key = 'tipoPonto' AND object_id = {$agent_id}");

            if ($coletivo_tipo_ponto == 'ponto_coletivo') {
                $category = 'Ponto de Cultura (coletivo sem CNPJ)';
            } else if ($coletivo_tipo_ponto == 'ponto_entidade') {
                $category = 'Ponto de Cultura (entidade com CNPJ)';
            } else if ($coletivo_tipo_ponto == 'pontao') {
                $category = 'Pontão de Cultura (entidade com CNPJ)';
            } else if ($user_meta->comCNPJ) {
                // @todo procurar maneira de identificar se é ponto ou pontão
                $category = 'Ponto de Cultura (entidade com CNPJ)';
            } else {
                $category = 'Ponto de Cultura (coletivo sem CNPJ)';
            }

            $app->log->debug("$reg_num - DEFININDO CATEGORIA $category");
            $conn->update('registration', ['category' => $category], ['id' => $registration->id]);
        });
    },

    'RCV - Insere as inscrições e agentes coletivos na fila de processamento de cache' => function () {
        $app = App::i();
        /** @var Connection */
        $conn = $app->em->getConnection();

        DB_UPDATE::enqueue('Agent', "type = 2", function (Agent $agent) use ($app, $conn) {

            if ($registration = $agent->rcv_registration) {
                $agent->enqueueToPCacheRecreation();
                $app->log->debug("Enfileira cache do agente {$agent->id}");

                $registration->enqueueToPCacheRecreation();
                $app->log->debug("Enfileira cache da inscrição {$registration->id}");
            }
        });
    },

    "RCV - Ajsuta categorias das inscrições que estão aguardando para serem avaliadas" => function () use ($config) {
        $app = App::i();

        $field_name = $config['rcv.fieldQuestion'];
        $question = $config['rcv.questionResponse'];
        $no_question = $config['rcv.questionNoResponse'];

        $opportunity_id = $config['rcv.opportunityId'];

        $opprtunity = $app->repo('Opportunity')->find($opportunity_id);
        $opprtunity->registerRegistrationMetadata();

        DB_UPDATE::enqueue('Registration', "opportunity_id = {$opportunity_id} and status = 1", function (Registration $registration) use ($app, $field_name, $question, $no_question, $config) {
            if ($registration->$field_name === $question) {
                $registration->range = $config['rcv.rangesMap']['cadastro-via-edital'];
                $app->log->debug("Atualiza faixa da inscrição {$registration->id} com valor `Cadastro via edital`");
            } else {

                if ($registration->$field_name !== $no_question) {
                    if (!$registration->range || !in_array($registration->range, ['Alteração de CNPJ', 'Alteração do tipo de organização', 'Desativar ponto'])) {
                        $registration->range = $config['rcv.rangesMap']['cadastro'];
                    }

                    $registration->$field_name = $no_question;
                    $app->log->debug("Atualiza faixa da inscrição {$registration->id} com valor `Cadastro`");
                }
            }

            $registration->save(true);
        });
    },

    'Normaliza campos de municípios' => function () {
        $app = App::i();

        $file = $app->view->resolveFilename('states-and-cities', 'brasil.php');
        include $file;

        $reference_data = [];
        foreach ($data as $state_code => $values) {
            foreach ($values['cities'] as $city_name) {
                $normalized_name = \MapasCulturais\Utils::sanitizeString($city_name, removeSpecials: true);
                $reference_data[$state_code][$normalized_name] = $city_name;
            }
        }

        $log_dir = PUBLIC_PATH . '/states_cities';
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $log_auto = "{$log_dir}/auto-corrected.txt";
        $log_review = "{$log_dir}/manual-review.txt";

        $pad_right = function ($string, $width) {
            $len = mb_strwidth($string, 'UTF-8');
            if ($len < $width) {
                return $string . str_repeat(' ', $width - $len);
            }
            return mb_strimwidth($string, 0, $width, '', 'UTF-8');
        };

        $format_line = function ($id, $state_code, $submitted_name, $suggested_name, $similarity) use ($pad_right) {
            return
                $pad_right($id, 15) . "  " .
                $pad_right($state_code, 3) . "  " .
                $pad_right($submitted_name, 50) . "  " .
                $pad_right($suggested_name, 30) . "  " .
                sprintf("%6.2f%%", $similarity);
        };

        $header =
            $pad_right('ID do Agente', 15) . "  " .
            $pad_right('UF', 3) . "  " .
            $pad_right('Munícipio atual', 50) . "  " .
            $pad_right('Sugestão para correção', 30) . "  " .
            'Percentual de similaridade';

        $state_normalize = function ($input) {
            $input = trim($input);

            if (preg_match('/\(([^()]+)\)/u', $input, $matches)) {
                return trim($matches[1]);
            }

            $ufs = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];
            if (preg_match('/^(.+?)(\s*[-–]?\s*\(?(' . implode('|', $ufs) . ')\)?\s*)$/iu', $input, $matches)) {
                return trim($matches[1]);
            }

            return $input;
        };

        file_put_contents($log_auto, $header . PHP_EOL);
        file_put_contents($log_review, $header . PHP_EOL);
        
        DB_UPDATE::enqueue('Agent', "id > 1", function (Agent $agent) use (
            $reference_data,
            $log_auto,
            $log_review,
            $format_line,
            $state_normalize,
        ) {

            $state_code = $agent->En_Estado;
            $submitted_city = $agent->En_Municipio;

            if (!$state_code || !$submitted_city || !isset($reference_data[$state_code])) {
                return;
            }

            $normalized_submitted = \MapasCulturais\Utils::sanitizeString($submitted_city, removeSpecials: true);
            $valid_normalized = array_keys($reference_data[$state_code]);

            if (in_array($normalized_submitted, $valid_normalized)) {
                return;
            }

            $normalized_submitted = $state_normalize($normalized_submitted);

            $best_original = null;
            $best_similarity = 0;

            foreach ($valid_normalized as $normalized_candidate) {
                similar_text($normalized_submitted, $normalized_candidate, $similarity);
                if ($similarity > $best_similarity) {
                    $best_similarity = $similarity;
                    $best_original = $reference_data[$state_code][$normalized_candidate];
                }
            }

            $line = $format_line(
                $agent->id,
                $state_code,
                $submitted_city,
                $best_original ?? 'Não encontrado referências',
                $best_similarity
            );

            if (!$best_original || $best_similarity < 70) {
                file_put_contents($log_review, $line . PHP_EOL, FILE_APPEND);
            } elseif ($best_similarity >= 85) {
                file_put_contents($log_auto, $line . PHP_EOL, FILE_APPEND);
                $agent->En_Municipio = $best_original;
                $agent->save(true);
            } else {
                file_put_contents($log_review, $line . PHP_EOL, FILE_APPEND);
            }
        });
    }
];
