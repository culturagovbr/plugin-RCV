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
    
        DB_UPDATE::enqueue('Registration', "opportunity_id = $opportunity_id", function(Registration $registration) use($app, $conn) {
            $reg_num = str_pad($registration->number, 20);
    
            $user_meta = $conn->fetchAssoc("SELECT * FROM user_meta WHERE object_id = {$registration->owner->user->id} AND key = 'redeCulturaViva'");
            if (is_string($user_meta['value'] ?? null)) {
                $user_meta = (object) json_decode($user_meta['value']);
            }
    
            if(!$user_meta) {
                $app->log->error("$reg_num - Erro ao tentar vincular coletivo e preencher category da inscrição para $registration");
                return;
            }
    
            // vincula o coletivo à inscrição;
            if ($registration->owner->id == $user_meta->agenteIndividual) {
                $agent_id = $user_meta->agentePonto;
    
                if(!$conn->fetchScalar("SELECT id FROM agent WHERE id = '$agent_id'")){
                    if($registration->status == 0) {
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
    
            if ($coletivo_tipo_ponto == 'ponto_coletivo'){
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
    
        DB_UPDATE::enqueue('Agent', "type = 2", function(Agent $agent) use($app, $conn) {
            
            if($registration = $agent->rcv_registration) {
                $agent->enqueueToPCacheRecreation();
                $app->log->debug("Enfileira cache do agente {$agent->id}");

                $registration->enqueueToPCacheRecreation();
                $app->log->debug("Enfileira cache da inscrição {$registration->id}");
            }
        });
    },

    "RCV - Ajsuta categorias das inscrições que estão aguardando para serem avaliadas" => function() use ($config) {
        $app = App::i();

        $field_name = $config['rcv.fieldQuestion'];
        $question = $config['rcv.questionResponse'];
        $no_question = $config['rcv.questionNoResponse'];

        $opportunity_id = $config['rcv.opportunityId'];

        $opprtunity = $app->repo('Opportunity')->find($opportunity_id);
        $opprtunity->registerRegistrationMetadata();

        DB_UPDATE::enqueue('Registration', "opportunity_id = {$opportunity_id} and status = 1", function(Registration $registration) use($app, $field_name, $question,$no_question, $config) {
            if($registration->$field_name === $question) {
                $registration->range = $config['rcv.rangesMap']['cadastro-via-edital'];
                $app->log->debug("Atualiza faixa da inscrição {$registration->id} com valor `Cadastro via edital`");
            } else {
                
                if($registration->$field_name !== $no_question) {
                    if(!$registration->range || !in_array($registration->range, ['Alteração de CNPJ', 'Alteração do tipo de organização', 'Desativar ponto']) ) {
                        $registration->range = $config['rcv.rangesMap']['cadastro'];
                    }
                    
                    $registration->$field_name = $no_question;
                    $app->log->debug("Atualiza faixa da inscrição {$registration->id} com valor `Cadastro`");
                }
            }

            $registration->save(true);

        });
    }
];
