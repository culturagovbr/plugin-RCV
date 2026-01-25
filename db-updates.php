<?php

use MapasCulturais\App;
use MapasCulturais as M;
use MapasCulturais\Entities\Registration;
use CulturaViva\JobTypes\JobsAFormTextUpdater;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\RegistrationSpaceRelation;
use MapasCulturais\Entities\Space;

use function MapasCulturais\__exec;

return [
    'migra campos @ para metadados do agente' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $de_para = [
            '22983' => 'foiFomentado',
            '22931' => 'tipoFomento',
            '22935' => 'esferaFomento',
            '22956' => 'rcv_edital_fomento',
            '23019' => 'rcv_fomento_distrital',
            '23018' => 'rcv_fomento_municipal',
        ];

        foreach ($de_para as $field => $meta) {

            $query = "
                SELECT rm.key, rm.value, ar.agent_id 
                FROM registration r 
                JOIN registration_meta rm on rm.object_id = r.id AND rm.key = 'field_{$field}'
                JOIN agent_relation ar on ar.object_type = 'MapasCulturais\Entities\Registration' AND ar.object_id = r.id AND type = 'coletivo'";

            $result = $conn->fetchAll($query);
            foreach ($result as $values) {
                if ($values && !$conn->fetchAll("SELECT id FROM agent_meta WHERE key = '{$meta}' and object_id = {$values['agent_id']}")) {
                    $conn->insert('agent_meta', [
                        'key' => $values['key'],
                        'object_id' => $values['agent_id'],
                        'value' => $values['value'],
                    ]);

                    $app->log->debug("Insere campo {$field} da inscrição no agente {$values['agent_id']}");
                } else {
                    $conn->update('agent_meta', [
                        'value' => $values['value'],
                    ], [
                        'key' => $values['key'],
                        'object_id' => $values['agent_id'],
                    ]);

                    $app->log->debug("Atualiza campo {$field} da inscrição no agente {$values['agent_id']}");
                }
            }
        }
    },

    'Corrige metadadp tem_sede do agente' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $query = "
        SELECT 
            object_id as id 
        FROM 
            registration_meta rm
            WHERE rm.key = 'field_22953' AND rm.value = '1'
        ";

        if ($registrationsId = $conn->fetchAll($query)) {
            foreach ($registrationsId as $velue) {
                $id = $velue['id'];

                $conn->update('registration_meta', [
                    'value' => 'Própria(o)',
                ], [
                    'key' => 'field_22953',
                    'object_id' => $id,
                ]);

                $app->log->debug("Atualiza campo field_22953 da inscrição {$id} para o valor Própria(o)");
            }
        }

        $query = "
        SELECT 
            object_id as id 
        FROM 
            agent_meta ag
            WHERE ag.key = 'tem_sede' AND ag.value = '1'
        ";

        if ($agentsId = $conn->fetchAll($query)) {
            foreach ($agentsId as $velue) {
                $id = $velue['id'];
                $conn->update('agent_meta', [
                    'value' => 'Própria(o)',
                ], [
                    'key' => 'tem_sede',
                    'object_id' => $id,
                ]);

                $app->log->debug("Atualiza metadado tem_sede do agente {$id} para o valor Própria(o)");
            }
        }

        $conn->executeQuery("DELETE from agent_meta where key = 'tem_sede' and value = '0'");
        $conn->executeQuery("DELETE from registration_meta where key = 'field_22953' and value = '0'");
    },
    'copia valor da coluna update_timestamp da inscrição para o metadado rcv_cad_updateTimestamp no agente' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $query = "
        SELECT 
            r.id,
            r.create_timestamp,
            ar.agent_id
        FROM 
            registration r 
            JOIN agent_relation ar on ar.object_type = 'MapasCulturais\Entities\Registration' AND ar.object_id = r.id AND type = 'coletivo'
        ";

        if ($registrationsId = $conn->fetchAll($query)) {
            foreach ($registrationsId as $value) {
                $id = $value['id'];
                $create_timestamp = $value['create_timestamp'];
                $agent_id = $value['agent_id'];

                $conn->insert('agent_meta', [
                    'key' => 'rcv_cad_updateTimestamp',
                    'object_id' => $agent_id,
                    'value' => $create_timestamp,
                ]);

                $app->log->debug("Atualiza metadado rcv_cad_updateTimestamp do agente {$id} com a data update_timestamp da inscrição");
            }
        }
    },

    'cria inscrições para as organizações sem inscrições' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $tmp_num = 'TMP-DB-UPDATE';
        $query = "
            SELECT 
                id AS coletivo_id, 
                name,
                parent_id AS owner_id,
                create_timestamp,
                update_timestamp 
            FROM agent 
            WHERE 
                    type=2 
                AND status > 0
                AND id IN (SELECT object_id FROM seal_relation where seal_id in (6,101) AND object_type = 'MapasCulturais\Entities\Agent') 
                AND id NOT IN (SELECT agent_id FROM agent_relation WHERE object_type = 'MapasCulturais\Entities\Registration' AND type = 'coletivo');
        ";

        if ($coletivos = $conn->fetchAll($query)) {
            foreach ($coletivos as $coletivo) {
                $coletivo = (object) $coletivo;

                $app->log->debug("Cria inscrição para o ponto #{$coletivo->coletivo_id} {$coletivo->name}");

                $id = $conn->fetchScalar(
                    'INSERT INTO registration (
                        opportunity_id,
                        number,
                        status,
                        category,
                        agent_id,
                        create_timestamp,
                        update_timestamp,
                        sent_timestamp,
                        subsite_id
                    ) VALUES (
                        :opportunity_id,
                        :number,
                        :status,
                        :category,
                        :agent_id,
                        :create_timestamp,
                        :update_timestamp,
                        :update_timestamp,
                        :subsite_id
                    ) RETURNING id',
                    [
                        'opportunity_id' => 5386,
                        'number' => $tmp_num,
                        'status' => 10,
                        'category' => 'Ponto de Cultura (entidade com CNPJ)',
                        'agent_id' => $coletivo->owner_id,
                        'create_timestamp' => $coletivo->create_timestamp,
                        'update_timestamp' => $coletivo->update_timestamp,
                        'subsite_id' => 8
                    ]
                );


                $conn->executeQuery("
                    UPDATE registration SET number = CONCAT('on-', id) WHERE number = '{$tmp_num}';
                ");

                $conn->insert('agent_relation', [
                    'agent_id' => $coletivo->coletivo_id,
                    'object_id' => $id,
                    'object_type' => 'MapasCulturais\Entities\Registration',
                    'type' => 'coletivo',
                ]);

                $conn->insert('permission_cache_pending', [
                    'object_id' => $id,
                    'object_type' => 'MapasCulturais\Entities\Registration'
                ]);

            }
        }
    },

    'define metadado rcv_registration nas organizações' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $query = "
            SELECT 
                r.id,
                ar.agent_id as agent_id
            FROM 
                registration r
                JOIN agent_relation ar ON ar.object_id = r.id AND ar.object_type = 'MapasCulturais\Entities\Registration' AND ar.type = 'coletivo'
            WHERE 
                r.opportunity_id = 5386
        ";

        if ($registrations = $conn->fetchAll($query)) {
            foreach ($registrations as $registration) {
                $registration = (object) $registration;

                $app->log->debug("Define metadado rcv_registration para o ponto #{$registration->agent_id}");

                $conn->insert('agent_meta', [
                    'key' => 'rcv_registration',
                    'object_id' => $registration->agent_id,
                    'value' => Registration::class . ':' . $registration->id,
                ]);
            }
        }
    }, 

    'Cria usuário fantasma que irá ficar com as avaliações após validação de atualização cadastral' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        // Verificar se o usuário já existe
        $existing_user_id = $conn->fetchScalar("SELECT id FROM usr WHERE email = 'userfake@userfake.com'");

        if(!$existing_user_id) {
            // Inserir o usuário fantasma
            $conn->executeQuery("
                INSERT INTO usr (auth_provider, auth_uid, email, status, profile_id, last_login_timestamp, create_timestamp)
                VALUES (0, 'userfake@userfake.com', 'userfake@userfake.com', 1, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
            ");
    
            // Obter o id do usuário inserido
            $user_id = $conn->fetchScalar("SELECT id FROM usr WHERE email = 'userfake@userfake.com'");
    
            // Inserir o agente associado ao usuário fantasma
            $conn->executeQuery("
                INSERT INTO agent (parent_id, user_id, type, name, short_description, create_timestamp, status, subsite_id)
                VALUES (NULL, $user_id, 1, 'Validador de atualização cadastral de Pontos e Pontões de Cultura', 
                        'Validador de atualização cadastral de Pontos e Pontões de Cultura', CURRENT_TIMESTAMP, -2, NULL);
            ");
    
            // Obter o id do agente inserido
            $agent_id = $conn->fetchScalar("SELECT id FROM agent WHERE user_id = $user_id");
    
            // Atualizar o profile_id do usuário para o id do agente
            $conn->executeQuery("
                UPDATE usr
                SET profile_id = $agent_id
                WHERE id = $user_id;
            ");
        }
    },
    'Normaliza metadado sede_realizaAtividades' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $conn->executeQuery("UPDATE agent_meta set value = 'Não' WHERE key = 'sede_realizaAtividades' AND value = 'false'");
        $conn->executeQuery("UPDATE agent_meta set value = 'Não' WHERE key = 'sede_realizaAtividades' AND value = '0'");
        $conn->executeQuery("UPDATE agent_meta set value = 'Sim' WHERE key = 'sede_realizaAtividades' AND value = '1'");
        $conn->executeQuery("UPDATE agent_meta set value = 'Sim' WHERE key = 'sede_realizaAtividades' AND value = 'true'");
    },

    'Atualiza o tipo de proponente de inscrições de acordo com a categoria' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();
        
        $conn->executeQuery("
            UPDATE registration
            SET proponent_type = CASE
                WHEN category IN ('Pontão de Cultura (entidade com CNPJ)', 'Ponto de Cultura (entidade com CNPJ)')
                THEN 'Pessoa Jurídica'
                WHEN category = 'Ponto de Cultura (coletivo sem CNPJ)'
                THEN 'Coletivo'
                ELSE proponent_type
            END
            WHERE opportunity_id = 5386;
        ");
    },
    'Passa as inscrições de organizações certificadas que estejam como RASCUNHO para selecionada' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $conn->executeQuery("
            update registration set status = 10 where id in (
                select  r.id
                from agent a 
                    right join seal_relation sr on sr.seal_id in (6,101) and sr.object_type  = 'MapasCulturais\Entities\Agent' and sr.object_id = a.id 
                    left join agent_relation ar on ar.agent_id = a.id and ar.type = 'coletivo' and ar.object_type = 'MapasCulturais\Entities\Registration'
                    left join registration r on r.opportunity_id = 5386 and r.id = ar.object_id 
                    left join seal on seal.id = sr.seal_id
                where 
                    a.status = 1 and 
                    r.status = 0 and
                    r.create_timestamp < '2025-01-01'
            );
        ");
    },

    'Passa as inscrições de organizações certificadas que estejam como NAO SELECIONADA para selecionada' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $conn->executeQuery("
            update registration set status = 10 where id in (
                select  r.id
                from agent a 
                    right join seal_relation sr on sr.seal_id in (6,101) and sr.object_type  = 'MapasCulturais\Entities\Agent' and sr.object_id = a.id 
                    left join agent_relation ar on ar.agent_id = a.id and ar.type = 'coletivo' and ar.object_type = 'MapasCulturais\Entities\Registration'
                    left join registration r on r.opportunity_id = 5386 and r.id = ar.object_id 
                    left join seal on seal.id = sr.seal_id
                where a.status = 1 and r.status = 3
            );
        ");
    },

    'Ajusta coluna status na tabela agent_relation' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();
        
        $conn->executeQuery("
            UPDATE 
                agent_relation set status = 1 
            WHERE object_type = 'MapasCulturais\Entities\Registration'
            AND object_id IN (
                SELECT id
                FROM registration r
                WHERE r.opportunity_id = 5386
            )
            AND status IS null");
    },

    'Normaliza coluna agentsData das inscrições' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();
        
        $null_registrations = $conn->fetchAllAssociative("
            SELECT id
            FROM registration
            WHERE opportunity_id = 5386
            AND status != 0
            AND agents_data IS NULL;
        ");

        foreach($null_registrations as $null_registration) {
            $registration = $app->repo('Registration')->find($null_registration['id']);
            
            $agents_data_json = json_encode($registration->_getAgentsData());

            $conn->executeQuery("UPDATE registration SET agents_data = :agents_data WHERE id = :id", [
                'agents_data' => $agents_data_json,
                'id' => $registration->id
            ]);
        }
    },

    'Apaga as inscrições do edital de ID 1' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();
        
        $conn->executeQuery("DELETE FROM registration WHERE opportunity_id = :id", [
            'id' => 1
        ]);
    },

    'remove inscrições duplicadas no edital 5386' => function () {
        $app = App::i();
        $em = $app->em;

        $query = "SELECT 
                        r.id, r.number, r.category, r.status,
                        r.create_timestamp, r.sent_timestamp,
                        a.id as owner_id, a.name as owner_name,
                        org.id AS organizacao_id, 
                        org.name AS organizacao_name,
                        count(DISTINCT(rm.id)) AS num_metadata,
                        count(DISTINCT(f.id)) AS num_arquivos
                    FROM registration r 
                        LEFT JOIN agent a ON a.id = r.agent_id
                        LEFT JOIN agent_relation ar ON ar.object_type = 'MapasCulturais\Entities\Registration' AND ar.object_id = r.id
                        JOIN agent org ON org.id = ar.agent_id 
                        LEFT JOIN registration_meta rm ON rm.object_id = r.id
                        LEFT JOIN file f ON f.object_type = 'MapasCulturais\Entities\Registration' AND f.object_id = r.id 
                    WHERE
                        r.opportunity_id = 5386 AND
                        org.id IN (
                            select 
                                _org.id 
                            FROM registration _r 
                                LEFT JOIN agent_relation _ar ON _ar.object_type  = 'MapasCulturais\Entities\Registration' AND _ar.object_id = _r.id
                                JOIN agent _org ON _org.id = _ar.agent_id 
                            WHERE
                                _r.opportunity_id = 5386
                            GROUP BY _org.id	
                            HAVING count(_r.id) > 1
                        )
                    GROUP BY r.id, r.number, r.category, r.status, r.create_timestamp, r.sent_timestamp, owner_id, owner_name, organizacao_id, organizacao_name
                    ORDER BY organizacao_id, num_metadata DESC, num_arquivos DESC, r.sent_timestamp DESC";


        $organizations = [];

        $registrations_to_exclude = [];
        $registrations_to_keep = [];
        $registrations_to_update = [];

        $all_registrations = $em->getConnection()->fetchAll($query);

        foreach($all_registrations as $reg) {
            $reg = (object) $reg;
            $item = $organizations[$reg->organizacao_id] ?? [
                'id' => $reg->organizacao_id,
                'name' => $reg->organizacao_name,
                'registrations' => [],
                'categories' => [],
                'statuses' => [],
            ];
            $item['registrations'][] = $reg;
            $item['categories'][$reg->category] = $item['categories'][$reg->category] ?? 0;
            $item['categories'][$reg->category]++;
            $item['statuses'][$reg->status] = $item['statuses'][$reg->status] ?? 0;
            $item['statuses'][$reg->status]++;
            
            $organizations[$reg->organizacao_id] = $item;
        }
        

        foreach($organizations as $org_id => $org) {
            $log = $org_id == 10636;
            $org = (object) $org;

            // todas as inscrições são da mesma categoria
            if (count($org->categories) == 1) {
                if($log) $app->log->debug('linha: ' . __LINE__);
                // se todas tem o mesmo status, mantém a com mais metadados e arquivos
                if (count($org->statuses) == 1) {
                    if($log) $app->log->debug('linha: -------------' . __LINE__);
                    
                    $reg = array_shift($org->registrations);
                    $registrations_to_keep[$reg->id] = $reg;
                    foreach($org->registrations as $r) {

                        $registrations_to_exclude[$r->id] = $r;
                    }
                    $org->registrations = [];

                // se há uma inscrição selecionada
                } else if ($org->statuses[10] ?? false) {
                    if($log) $app->log->debug('linha: -------------' . __LINE__);

                    // inscrição com mais metadados
                    $reg = array_shift($org->registrations);
                    $registrations_to_keep[$reg->id] = $reg;
                    if($reg->status != 10) {
                        // muda o status da inscrição para selecionada
                        $registrations_to_update[$reg->id] = ['status' => 10];
                    }
                    
                    foreach($org->registrations as $r) {
                        $registrations_to_exclude[$r->id] = $r;
                    }
                    $org->registrations = [];

                // se há apenas uma inscrição enviada e o restante em rascunho mantem a enviada
                } else if(count($org->registrations) === ($org->statuses[0] ?? 0) + ($org->statuses[1] ?? 0) && ($org->statuses[1] ?? 0) === 1) {
                    if($log) $app->log->debug('linha: -------------' . __LINE__);
                    $regs = $org->registrations;
                    foreach($regs as $index => $reg) {
                        if($reg->status == 1) {
                            $registrations_to_keep[$reg->id] = $reg;
                        } else {
                            $registrations_to_exclude[$reg->id] = $reg;
                        }
                        unset($org->registrations[$index]);
                    }
                } else {
                    if($log) $app->log->debug('linha: -------------' . __LINE__);
                    // mantém as inscrições com status 2, 3 e 8
                    $regs = $org->registrations;
                    foreach($regs as $index => $reg) {
                        if(in_array($reg->status, [2, 3, 8])) {
                            $registrations_to_keep[$reg->id] = $reg;
                            unset($org->registrations[$index]);
                        }
                    }

                    $reg_to_keep = null;
                    // obtem a inscrição enviada com mais metadados

                    $regs = $org->registrations;
                    foreach($regs as $index => $reg) {
                        if ($reg->status != 1) {
                            continue;
                        }

                        if (!$reg_to_keep) {
                            $reg_to_keep = $reg;
                            $registrations_to_keep[$reg->id] = $reg;
                        } else {
                            $registrations_to_exclude[$reg->id] = $reg;
                        }

                        unset($org->registrations[$index]);
                    }

                    $regs = $org->registrations;
                    foreach($regs as $index => $reg) {
                        if($reg_to_keep) {
                            $registrations_to_exclude[$reg->id] = $reg;
                        } else {
                            $reg_to_keep = $reg;
                            $registrations_to_keep[$reg->id] = $reg;
                        }
                        unset($org->registrations[$index]);
                    }
                }

            // só tem inscrições rascunho ou só tem inscrições enviadas pendentes de avaliação
            } else if (count($org->statuses) == 1 && (($org->statuses[0] ?? false) || ($org->statuses[1] ?? false))) {
                if($log) $app->log->debug('linha: ' . __LINE__);

                // se tem alguma SEM CNPJ apaga pois se a organização tem CNPJ, a inscrição SEM CNPJ é inválida
                $regs = $org->registrations;
                foreach($regs as $index => $reg) {
                    if($reg->category == 'Ponto de Cultura (coletivo sem CNPJ)') {
                        $registrations_to_exclude[$reg->id] = $reg;
                        // remove a inscrição da lista de inscrições da organização
                        unset($org->registrations[$index]);
                    }
                }

                $to_keep = [];
                $regs = $org->registrations;
                foreach($regs as $index=> $reg) {
                    if(!isset($to_keep[$reg->category])) {
                        // a primeira inscrição de cada categoria é mantida pois é a com maior quantidade de metadados e arquivos
                        // obs: foi marcada para deleção no loop anterior as inscrições SEM CNPJ e são válidas inscrições simultâneas para ponto e pontão
                        $to_keep[$reg->category] = $reg;
                        $registrations_to_keep[$reg->id] = $reg;
                    } else {
                        $registrations_to_exclude[$reg->id] = $reg;
                    }

                    unset($org->registrations[$index]);
                }

            // tem uma seleciona 
            } else if($org->statuses[10] ?? false ) {
                if($log) $app->log->debug('linha: ' . __LINE__);

                if($org->statuses[10] > 1) {
                    $app->log->debug("MAIS QUE UMA SELECIONADA, LINHA" . __LINE__);
                }

                // obtem categoria da inscrição selecionada
                $selected_category = null;
                foreach($org->registrations as $reg) {
                    if($reg->status == 10) {
                        $selected_category = $reg->category;
                        break;
                    }
                }

                // obtem inscrição com mais metadados e arquivos da categoria da inscrição selecionada
                $selected_registration = null;
                $regs = $org->registrations;
                foreach($regs as $index => $reg) {
                    if($reg->category == $selected_category) {
                        $selected_registration = $reg;
                        $registrations_to_keep[$reg->id] = $selected_registration;
                        break;
                    }
                }

                // se a inscrição com mais metadados não for a selecionada, muda o status para selecionada
                if($selected_registration->status != 10) {
                    $registrations_to_update[$selected_registration->id] = ['status' => 10];
                }


                // define as categorias incompativeis com a categoria da inscrição selecionada
                if($selected_category == 'Pontão de Cultura (entidade com CNPJ)') {
                    $categories_to_exclude = ['Pontão de Cultura (entidade com CNPJ)', 'Ponto de Cultura (coletivo sem CNPJ)'];
                } else if($selected_category == 'Ponto de Cultura (entidade com CNPJ)') {
                    $categories_to_exclude = ['Ponto de Cultura (entidade com CNPJ)', 'Ponto de Cultura (coletivo sem CNPJ)'];
                } else if($selected_category == 'Ponto de Cultura (coletivo sem CNPJ)') {
                    $categories_to_exclude = ['Ponto de Cultura (coletivo sem CNPJ)'];
                }

                // apaga as inscrições incompatíveis
                $regs = $org->registrations;
                foreach($regs as $index => $reg) {
                    if(in_array($reg->category, $categories_to_exclude) || $reg->category == $selected_category) {
                        $registrations_to_exclude[$reg->id] = $reg;
                        unset($org->registrations[$index]);
                    }
                }

                // mantém a com mais metadados das inscrições restantes, que nào são de categorias incompatíveis
                $to_keep_from_category = [];
                $regs = $org->registrations;
                foreach($regs as $index => $reg) {
                    if(!isset($to_keep_from_category[$reg->category])) {
                        $to_keep_from_category[$reg->category] = $reg;
                        $registrations_to_keep[$reg->id] = $reg;
                    } else {
                        $registrations_to_exclude[$reg->id] = $reg;
                    }

                    unset($org->registrations[$index]);
                }

            // se há apenas uma enviada e o restante em rascunho, mantém a enviada
            } else if(count($org->registrations) === ($org->statuses[0] ?? 0) + ($org->statuses[1] ?? 0) && ($org->statuses[1] ?? 0) === 1) {
                if($log) $app->log->debug('linha: ' . __LINE__);

                $regs = $org->registrations;
                foreach($regs as $index => $reg) {
                    if($reg->status == 1) {
                        $registrations_to_keep[$reg->id] = $reg;
                    } else {
                        $registrations_to_exclude[$reg->id] = $reg;
                    }
                    unset($org->registrations[$index]);
                }
            
            // se há apenas uma enviada e não selecionada, inválida ou suplente e o restante em rascunho, mantém a enviada e a rascunho com mais metadados e anexos
            } else if(
                    ((($org->statuses[2] ?? 0) == 1) && count($org->registrations) == $org->statuses[2] + ($org->statuses[0] ?? 0)) || 
                    ((($org->statuses[3] ?? 0) == 1) && count($org->registrations) == $org->statuses[3] + ($org->statuses[0] ?? 0)) || 
                    ((($org->statuses[8] ?? 0) == 1) && count($org->registrations) == $org->statuses[8] + ($org->statuses[0] ?? 0)) ) {
    
                    if($log) $app->log->debug('linha: ' . __LINE__);

                
                    $to_keep_from_category = [];
                    $regs = $org->registrations;
                    foreach($regs as $index => $reg) {
                        if($reg->status != 0) {
                            $registrations_to_keep[$reg->id] = $reg;
                        } else {
                            if(!isset($to_keep_from_category[$reg->category])) {
                                $to_keep_from_category[$reg->category] = $reg;
                            } else {
                                $registrations_to_exclude[$reg->id] = $reg;
                            }
                        }
                        if(count($to_keep_from_category) > 1 && isset($to_keep_from_category['Ponto de Cultura (coletivo sem CNPJ)'])) {
                            $r = $to_keep_from_category['Ponto de Cultura (coletivo sem CNPJ)'];
                            $registrations_to_exclude[$r->id] = $r;
                            unset($to_keep_from_category['Ponto de Cultura (coletivo sem CNPJ)']);
                        }
                        
                        unset($org->registrations[$index]);
                    }

                    foreach($to_keep_from_category as $reg) {
                        $registrations_to_keep[$reg->id] = $reg;
                    }
            } else if(
                ((($org->statuses[2] ?? 0) == 1) && count($org->registrations) == $org->statuses[2] + $org->statuses[1] ?? 0) || 
                ((($org->statuses[3] ?? 0) == 1) && count($org->registrations) == $org->statuses[3] + $org->statuses[1] ?? 0) || 
                ((($org->statuses[8] ?? 0) == 1) && count($org->registrations) == $org->statuses[8] + $org->statuses[1] ?? 0) ) {
                if($log) $app->log->debug('linha: ' . __LINE__);
            
                $to_keep_from_category = [];
                $regs = $org->registrations;
                foreach($regs as $index => $reg) {
                    if($reg->status != 1) {
                        $registrations_to_keep[$reg->id] = $reg;
                    } else {
                        if(!isset($to_keep_from_category[$reg->category])) {
                            $to_keep_from_category[$reg->category] = $reg;
                        } else {
                            $registrations_to_exclude[$reg->id] = $reg;
                        }
                    }
                    if(count($to_keep_from_category) > 1 && isset($to_keep_from_category['Ponto de Cultura (coletivo sem CNPJ)'])) {
                        $r = $to_keep_from_category['Ponto de Cultura (coletivo sem CNPJ)'];
                        $registrations_to_exclude[$r->id] = $r;
                        unset($to_keep_from_category['Ponto de Cultura (coletivo sem CNPJ)']);
                    }
                    
                    unset($org->registrations[$index]);
                }

                foreach($to_keep_from_category as $reg) {
                    $registrations_to_keep[$reg->id] = $reg;
                }

            // se há mais de uma enviada e o restante em rascunho, mantém as enviadas com mais metadados dentro da mesma categoria, mantém
            // as enviadas que não sejam conflitantes e apaga as rascunho conflitantes
            } else if(count($org->registrations) === ($org->statuses[0] ?? 0) + ($org->statuses[1] ?? 0) && ($org->statuses[1] ?? 0) > 1) {
                if($log) $app->log->debug('linha: ' . __LINE__);
                
                // obtem as enviadas com mais metadados de cada categoria e exclui demais enviadas
                $sent_by_category = [];
                $regs = $org->registrations;
                foreach($regs as $index => $reg) {
                    if($reg->status != 1) {
                        continue;
                    }

                    if(!isset($sent_by_category[$reg->category])) {
                        $sent_by_category[$reg->category] = $reg;
                    } else {
                        $registrations_to_exclude[$reg->id] = $reg;
                    }
                    unset($org->registrations[$index]);
                }

                $must_delete_sem_cnpj = false;
                // apaga as enviadas conflitantes
                if(count($sent_by_category) > 1 && isset($sent_by_category['Ponto de Cultura (coletivo sem CNPJ)'])) {
                    $r = $sent_by_category['Ponto de Cultura (coletivo sem CNPJ)'];
                    $registrations_to_exclude[$r->id] = $r;
                    unset($sent_by_category['Ponto de Cultura (coletivo sem CNPJ)']);
                    $must_delete_sem_cnpj = true;
                }

                // exclui as inscrições em rascunho que sejam conflitantes ou de categorias que possuem inscrições enviadas
                $regs = $org->registrations;
                foreach($regs as $index => $reg) {
                    if($reg->status != 0) {
                        continue;
                    }

                    if(isset($sent_by_category[$reg->category])) {
                        $registrations_to_exclude[$reg->id] = $reg;
                    } else if($must_delete_sem_cnpj && $reg->category == 'Ponto de Cultura (coletivo sem CNPJ)') {
                        $registrations_to_exclude[$reg->id] = $reg;
                    } else {
                        $registrations_to_keep[$reg->id] = $reg;
                    }
                    unset($org->registrations[$index]);
                }

                foreach($sent_by_category as $reg) {
                    $registrations_to_keep[$reg->id] = $reg;
                }
            
            // mantém as inscriçòes com status 2, 3 e 8 e apaga as rascunho e enviadas conflitantes
            } else if (!($org->statuses[10] ?? 0)) {
                if($log) $app->log->debug('linha: ' . __LINE__);

                // mantém as inscrições com status 2, 3 e 8
                $regs = $org->registrations;
                foreach($regs as $index => $reg) {
                    if(in_array($reg->status, [2, 3, 8])) {
                        $registrations_to_keep[$reg->id] = $reg;
                        unset($org->registrations[$index]);
                    }
                }

                // obtem as inscrições enviadas com maior número de metadados de cada categoria
                $sent_by_category = [];
                $regs = $org->registrations;
                foreach($regs as $index => $reg) {
                    if($reg->status != 1) {
                        continue;
                    }

                    if(!isset($sent_by_category[$reg->category])) {
                        $sent_by_category[$reg->category] = $reg;
                    } else {
                        $registrations_to_exclude[$reg->id] = $reg;
                    }
                    unset($org->registrations[$index]);
                }

                // apaga as enviadas conflitantes
                $regs = $org->registrations;
                // apaga as enviadas conflitantes
                if(count($sent_by_category) > 1 && isset($sent_by_category['Ponto de Cultura (coletivo sem CNPJ)'])) {
                    $r = $sent_by_category['Ponto de Cultura (coletivo sem CNPJ)'];
                    $registrations_to_exclude[$r->id] = $r;
                    unset($sent_by_category['Ponto de Cultura (coletivo sem CNPJ)']);
                    $must_delete_sem_cnpj = true;
                }

                // exclui as inscrições em rascunho que sejam conflitantes ou de categorias que possuem inscrições enviadas
                $regs = $org->registrations;
                foreach($regs as $index => $reg) {
                    if($reg->status != 0) {
                        continue;
                    }

                    if(isset($sent_by_category[$reg->category])) {
                        $registrations_to_exclude[$reg->id] = $reg;
                    } else if($must_delete_sem_cnpj && $reg->category == 'Ponto de Cultura (coletivo sem CNPJ)') {
                        $registrations_to_exclude[$reg->id] = $reg;
                    } else {
                        $registrations_to_keep[$reg->id] = $reg;
                    }
                    unset($org->registrations[$index]);
                }

                foreach($sent_by_category as $reg) {
                    $registrations_to_keep[$reg->id] = $reg;
                }

            } else {
                if($log) $app->log->debug('ELSE LINHA: ' . __LINE__);
            }

            if(count($org->registrations)){
                $app->log->debug(count($org->registrations) . " ---- Organização #{$org->id} {$org->name}");
            }

        }

        // reestrutura as inscriões com duplicidades

        foreach($organizations as &$org) {
            $org['registrations'] = [];
        }

        foreach($registrations_to_keep as $reg) {
            $organizations[$reg->organizacao_id]['registrations'][] = $reg;
        }

        $orgs_com_duplicadas = [];

        foreach($organizations as $org){
            $org = (object) $org;
            
            if(count($org->registrations) > 1) {
                $orgs_com_duplicadas[] = $org;
            }
        }
        
        /** @var \MapasCulturais\Connection */
        $conn = $app->em->getConnection();

        // Exclui inscrições duplicadas
        foreach($registrations_to_exclude as $reg) {
            $app->log->debug("Excluindo inscrição #{$reg->id} da organização #{$reg->organizacao_id} {$reg->organizacao_name}");
            $conn->delete('registration', ['id' => $reg->id]);
            file_put_contents(VAR_PATH . 'logs/rcv-duplicadas.log', "REMOVIDA {$reg->number} da organização #{$reg->organizacao_id} {$reg->organizacao_name}\n", FILE_APPEND);
        }

        // Atualiza inscrições
        foreach($registrations_to_update as $id => $data) {
            $app->log->debug("Atualizando inscrição #{$id} da organização #{$reg->organizacao_id} {$reg->organizacao_name}");
            $conn->update('registration', $data, ['id' => $id]);
            file_put_contents(VAR_PATH . 'logs/rcv-duplicadas.log', "ATUALIZADA {$reg->number} da organização #{$reg->organizacao_id} {$reg->organizacao_name}\n", FILE_APPEND);
        }

        // Adiciona inscrições que sobraram na geração de cache
        foreach($registrations_to_keep as $reg) {
            $app->log->debug("Adicionando inscrição #{$reg->id} da organização #{$reg->organizacao_id} {$reg->organizacao_name}");
            $conn->insert('permission_cache_pending', [
                'object_id' => $reg->id,
                'object_type' => 'MapasCulturais\Entities\Registration'
            ]);
        }
    },

    'Atualiza Job de acompanhamento das inscrições sinalizadas com Sim. Estou ciente de que a minha certificação dependerá da avaliação pela Comissão de Seleção do edital da Cultura Viva em que estou concorrendo' => function(){
        $app = App::i();
        $start_string = date('Y-m-d 00:00:00',strtotime('tomorrow'));
        $interval_string = '+24 hours';
        $iterations = 3650;

        $job = $app->enqueueOrReplaceJob(JobsAFormTextUpdater::SLUG,[],$start_string,$interval_string,$iterations);
        $job->save(true);
        $job->subsite = $app->repo('Subsite')->find('8');

        $app->log->debug('Deletando JobsAFormTextUpdater');

        return false;
    },

    'define metadado rcv_registration nas organizações que ainda não possuem' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $query = "
            SELECT 
                r.id,
                ar.agent_id as agent_id
            FROM 
                registration r
                JOIN agent_relation ar 
                    ON ar.object_id = r.id 
                    AND ar.object_type = 'MapasCulturais\\Entities\\Registration' 
                    AND ar.type = 'coletivo'
            WHERE 
                r.opportunity_id = 5386
        ";

        if ($registrations = $conn->fetchAll($query)) {
            foreach ($registrations as $registration) {
                $registration = (object) $registration;

                // Verifica se o metadado rcv_registration já existe na organização
                $rcv_registration_exists = $conn->fetchOne("
                    SELECT 1 FROM agent_meta 
                    WHERE object_id = :agent_id 
                    AND key = 'rcv_registration'
                    LIMIT 1
                ", ['agent_id' => $registration->agent_id]);

                // Se não encontrar o metadado na organização, insere a inscrição no metadado
                if (!$rcv_registration_exists) {
                    $app->log->debug("Define metadado rcv_registration para o ponto #{$registration->agent_id}");

                    $conn->insert('agent_meta', [
                        'key' => 'rcv_registration',
                        'object_id' => $registration->agent_id,
                        'value' => Registration::class . ':' . $registration->id,
                    ]);
                }
            }
        }
    },

    'Normaliza valor do campo da inscrição field_22897' => function () {
        $app = App::i();
        $em = $app->em;
        $conn = $em->getConnection();
        $field_location = 'field_22897';
        $opportunity_id = 5386;

        $query = "
            SELECT id, object_id, value
            FROM registration_meta
            WHERE key = '{$field_location}'
        ";

        if ($rows = $conn->fetchAllAssociative($query)) {
            foreach ($rows as $row) {
                $id = $row['id'];
                $registration_id = $row['object_id'];
                $registration = $app->repo('Registration')->find($registration_id);

                if($registration->opportunity->id == $opportunity_id) {
                    $value = json_decode($row['value'], true);

                    // Trata o valor se estiver com mais coisas dentro do objeto
                    if (isset($value[$field_location]) && is_array($value[$field_location])) {
                        unset($value[$field_location]);
                        
                        $newValue = $value;

                        $conn->update('registration_meta', [
                            'value' => json_encode($newValue, JSON_UNESCAPED_UNICODE),
                        ], ['id' => $id]);
                    }
                }
            }
        }
    },

    'Atualiza consolidated_result para Habilitado onde for 10' => function () {
        return false;
        $app = App::i();
        $em = $app->em;
        $conn = $em->getConnection();
        $opportunity_id = 5386;

        $query = "
            SELECT id
            FROM registration
            WHERE opportunity_id = {$opportunity_id}
            AND consolidated_result = '10'
            AND status = 10
        ";

        $rows = $conn->fetchAllAssociative($query);

        foreach ($rows as $row) {
            $registration_id = $row['id'];

            $conn->update('registration', [
                'consolidated_result' => 'Habilitado',
            ], ['id' => $registration_id]);
        }
    },

    'Normaliza valores do campo field_22949' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        // Atualiza valores não numéricos ou numéricos maiores que 12 para 12
        $conn->executeQuery("
            UPDATE registration_meta 
            SET value = '12' 
            WHERE key = 'field_22949' 
            AND (
                (value ~ '^\d+$' AND CAST(value AS integer) > 12)
                OR value IS NULL
                OR value !~ '^\d+$'
            );
        ");
    },

    'Define faixa para inscrições que ainda não possuem' => function () {
        $app = App::i();
        $em = $app->em;
        $conn = $em->getConnection();

        // Atualiza inscrições da oportunidade 5386 onde não contenha faixa
        $conn->executeQuery("
            UPDATE registration
            SET range = 'Cadastro'
            WHERE opportunity_id = 5386
            AND (range IS NULL OR TRIM(range) = '');
        ");
    },

    'Normaliza coluna agentsData das inscrições que não possuem o coletivo preenchido' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();
        
        $registrations = $conn->fetchAllAssociative("
            SELECT id
            FROM registration
            WHERE opportunity_id = 5386
            AND status > 0
        ");

        foreach($registrations as $reg) {
            $registration = $app->repo('Registration')->find($reg['id']);
            $old_agents_data = $registration->agents_data;

            if((!$old_agents_data) || ($old_agents_data && !isset($old_agents_data['coletivo']))) {
                $agents_data = $registration->_getAgentsData();

                if($old_owner = $old_agents_data['owner'] ?? null) {
                    $agents_data['owner'] = $old_owner;
                }

                $agents_data_json = json_encode($agents_data);

                $conn->executeQuery("UPDATE registration SET agents_data = :agents_data WHERE id = :id", [
                    'agents_data' => $agents_data_json,
                    'id' => $registration->id
                ]);
            }
            $app->em->clear();
        }
    },

    'implementa visão no banco para trabalhar com os gráficos no metabase' => function() {
        return false;
        $app = App::i();
        $em = $app->em;
        $conn = $em->getConnection();

        $conn->executeQuery("
            CREATE OR REPLACE VIEW rcv_bi AS
            SELECT
                a.id AS \"Id do agente\",
                a.name AS \"Nome do agente\",
                s.name AS \"Selo\",
                CASE
                    WHEN a.status = '-10' THEN 'Na lixeira'
                    WHEN a.status = '-2' THEN 'Arquivado'
                    WHEN a.status = '0' THEN 'Em rascunho'
                    WHEN a.status = '1' THEN 'Publicado'
                END AS \"Status\",
                a.location,
                INITCAP(municipio.value) AS \"Município\",
                estado.value AS \"Estado\",
                INITCAP(pais.value) AS \"Pais\",
                INITCAP(rcv.value) AS \"RCV\",
                INITCAP(esfera.value) AS \"Esfera de Fomento\",
                cnpj.value AS \"CNPJ\",
                ponto.value AS \"Tipo de Ponto\",
                raca.value AS \"Raça\",
                rcv_meses_media_ano_org.value AS \"Participantes por ano\",
                CASE
                    WHEN rcv_meses_media_ano_org.value = 'Até 50 pessoas por mês' THEN 10
                    WHEN rcv_meses_media_ano_org.value = 'Entre 50 e 100 pessoas por ano' THEN 50
                    WHEN rcv_meses_media_ano_org.value = 'Entre 101 e 200 pessoas por ano' THEN 100
                    WHEN rcv_meses_media_ano_org.value = 'Entre 201 e 500 pessoas por ano' THEN 200
                    WHEN rcv_meses_media_ano_org.value = 'Entre 501 e 1000 pessoas por ano' THEN 500
                    WHEN rcv_meses_media_ano_org.value = 'Entre 1001 e 2000 pessoas por ano' THEN 1000
                    WHEN rcv_meses_media_ano_org.value = 'Entre 2001 e 5000 pessoas por ano' THEN 2000
                    WHEN rcv_meses_media_ano_org.value = 'Entre 5001 e 10.000 pessoas por ano' THEN 5000
                    WHEN rcv_meses_media_ano_org.value = 'Mais de 10.000 pessoas por ano' THEN 10000
                END AS \"Qtd Participantes Numérica\",
                orientacaoSexual.value AS \"Orientação Sexual\",
                rcv_quantidade_trabalhadores.value AS \"Número de Trabalhadores\",
                rcv_valor_min_organizacao.value AS \"Valor anual mínimo\",
                CASE
                    WHEN rcv_valor_min_organizacao.value = 'Até R$81.000,00' THEN 10
                    WHEN rcv_valor_min_organizacao.value = 'Entre R$81.000,01 e R$180.000' THEN 81
                    WHEN rcv_valor_min_organizacao.value = 'Entre 180.000,01 e R$360.000,00' THEN 180
                    WHEN rcv_valor_min_organizacao.value = 'Entre R$360.000,01 e R$500.000' THEN 360
                    WHEN rcv_valor_min_organizacao.value = 'Entre R$500.000,01 e R$800.000' THEN 500
                    WHEN rcv_valor_min_organizacao.value = 'Entre R$800.000,01 e R$1.000.000,00' THEN 800
                    WHEN rcv_valor_min_organizacao.value = 'Entre R$1.000.000,01 e R$2.000.000' THEN 1000
                    WHEN rcv_valor_min_organizacao.value = 'Entre R$2.000.000,01 e R$4.800.000,00' THEN 2000
                    WHEN rcv_valor_min_organizacao.value = 'Acima de R$4.800.000,01' THEN 4800
                END AS \"Valor Minimo Numérico\",
                rcv_valor_total_organizacao.value AS \"Valor total anual\",
                CASE
                    WHEN rcv_valor_total_organizacao.value = 'Até R$81.000,00' THEN 10
                    WHEN rcv_valor_total_organizacao.value = 'Entre R$81.000,01 e R$180.000' THEN 81
                    WHEN rcv_valor_total_organizacao.value = 'Entre 180.000,01 e R$360.000,00' THEN 180
                    WHEN rcv_valor_total_organizacao.value = 'Entre R$360.000,01 e R$500.000' THEN 360
                    WHEN rcv_valor_total_organizacao.value = 'Entre R$500.000,01 e R$800.000' THEN 500
                    WHEN rcv_valor_total_organizacao.value = 'Entre R$800.000,01 e R$1.000.000,00' THEN 800
                    WHEN rcv_valor_total_organizacao.value = 'Entre R$1.000.000,01 e R$2.000.000' THEN 1000
                    WHEN rcv_valor_total_organizacao.value = 'Entre R$2.000.000,01 e R$4.800.000,00' THEN 2000
                    WHEN rcv_valor_total_organizacao.value = 'Acima de R$4.800.000,01' THEN 4800
                END AS \"Valor Total Numérico\",
                cast(data_fundacao.value as date) AS \"Data de fundação\",
                CASE
                    WHEN estado.value IN ('AC', 'AP', 'AM', 'PA', 'RO', 'RR', 'TO') THEN 'Norte Amazonia Legal'
                    WHEN estado.value IN ('AL', 'BA', 'CE', 'MA', 'PB', 'PE', 'PI', 'RN', 'SE') THEN 'Nordeste'
                    WHEN estado.value IN ('DF', 'GO', 'MT', 'MS') THEN 'Centro-Oeste'
                    WHEN estado.value IN ('ES', 'MG', 'RJ', 'SP') THEN 'Sudeste'
                    WHEN estado.value IN ('PR', 'RS', 'SC') THEN 'Sul'
                END AS \"Região\",
                CASE
                    WHEN estado.value IN ('AC', 'AP', 'AM', 'PA', 'RO', 'RR', 'TO', 'MT', 'MA') THEN True
                    ELSE False
                END AS \"Amazonia Legal\"
            FROM
                agent a
            LEFT JOIN agent_meta municipio ON a.id = municipio.object_id AND municipio.key = 'En_Municipio'
            LEFT JOIN agent_meta estado ON a.id = estado.object_id AND estado.key = 'En_Estado'
            LEFT JOIN agent_meta pais ON a.id = pais.object_id AND pais.key = 'pais'
            LEFT JOIN agent_meta rcv ON a.id = rcv.object_id AND rcv.key = 'rcv_tipo'
            LEFT JOIN agent_meta raca ON a.id = raca.object_id AND raca.key = 'raca'
            LEFT JOIN agent_meta rcv_meses_media_ano_org ON a.id = rcv_meses_media_ano_org.object_id AND rcv_meses_media_ano_org.key = 'rcv_meses_media_ano_org'
            LEFT JOIN agent_meta esfera ON a.id = esfera.object_id AND esfera.key = 'esferaFomento'
            LEFT JOIN agent_meta data_fundacao ON a.id = data_fundacao.object_id AND data_fundacao.key = 'dataDeNascimento'
            LEFT JOIN agent_meta ponto ON a.id = ponto.object_id AND ponto.key = 'tipoPonto'
            LEFT JOIN agent_meta rcv_quantidade_trabalhadores ON a.id = rcv_quantidade_trabalhadores.object_id AND rcv_quantidade_trabalhadores.key = 'rcv_quantidade_trabalhadores'
            LEFT JOIN agent_meta orientacaoSexual ON a.id = orientacaoSexual.object_id AND orientacaoSexual.key = 'orientacaoSexual'
            LEFT JOIN agent_meta rcv_valor_min_organizacao ON a.id = rcv_valor_min_organizacao.object_id AND rcv_valor_min_organizacao.key = 'rcv_valor_min_organizacao'
            LEFT JOIN agent_meta rcv_valor_total_organizacao ON a.id = rcv_valor_total_organizacao.object_id AND rcv_valor_total_organizacao.key = 'rcv_valor_total_organizacao'
            LEFT JOIN seal_relation selo ON a.id = selo.object_id AND selo.object_type = 'MapasCulturais\\Entities\\Agent'
            LEFT JOIN seal s ON selo.seal_id = s.id
            LEFT JOIN agent_meta cnpj ON a.id = cnpj.object_id AND cnpj.key = 'cnpj'
            WHERE
                a.type = '2'
                AND selo.seal_id IN (6, 101)
                AND rcv.value = 'ponto'
                AND a.status > 0
        ");

    },

    'normalização dos metadados de estado' => function () {
        $estados = [
                'AC'=>'Acre',
                'AL'=>'Alagoas',
                'AP'=>'Amapá',
                'AM'=>'Amazonas',
                'BA'=>'Bahia',
                'CE'=>'Ceará',
                'DF'=>'Distrito Federal',
                'ES'=>'Espírito Santo',
                'GO'=>'Goiás',
                'MA'=>'Maranhão',
                'MT'=>'Mato Grosso',
                'MS'=>'Mato Grosso do Sul',
                'MG'=>'Minas Gerais',
                'PA'=>'Pará',
                'PB'=>'Paraíba',
                'PR'=>'Paraná',
                'PE'=>'Pernambuco',
                'PI'=>'Piauí',
                'RJ'=>'Rio de Janeiro',
                'RN'=>'Rio Grande do Norte',
                'RS'=>'Rio Grande do Sul',
                'RO'=>'Rondônia',
                'RR'=>'Roraima',
                'SC'=>'Santa Catarina',
                'SP'=>'São Paulo',
                'SE'=>'Sergipe',
                'TO'=>'Tocantins',
        ];

        foreach($estados as $uf => $name) {
            echo "\nnormalizando En_Estado $name => $uf";
            __exec("UPDATE agent_meta SET value = '{$uf}' WHERE key = 'En_Estado' AND lower(unaccent(value)) = lower(unaccent('{$name}'))");

            echo "\nnormalizando En_EstadoPontaPontao $name => $uf";
            __exec("UPDATE agent_meta SET value = '{$uf}' WHERE key = 'En_EstadoPontaPontao' AND lower(unaccent(value)) = lower(unaccent('{$name}'))");
        }

        // remove os metadados En_EstadoPontaPontao das organizações sediadas fora do Brasil.
        __exec("DELETE FROM agent_meta WHERE key = 'En_EstadoPontaPontao' AND object_id IN (SELECT object_id FROM agent_meta WHERE key = 'paisPontaPontao' AND value = 'Brasil')");
    },

    'normalização dos metadados de país' => function () {
        __exec("INSERT INTO agent_meta (object_id, key, value)
                SELECT a.id, 'paisPontaPontao', am1.value 
                FROM agent a 
                    LEFT JOIN agent_meta am1 ON am1.object_id = a.id AND am1.key = 'pais' 
                    LEFT JOIN agent_meta am2 ON am2.object_id = a.id AND am2.key = 'paisPontaPontao' 
                WHERE am1.value IS NOT null AND trim(am1.value) <> '' AND am2.key is null
            ");
    },

    'normalização da faixa das inscrições' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();
        $config = include THEMES_PATH . 'CulturaViva/conf-base.php';
        $opportunity_id = $config['rcv.opportunityId'];
        $field = $config['rcv.fieldQuestion'];
        $response = $config['rcv.questionResponse'];

        $conn->executeQuery("
            UPDATE registration r
            SET range = 'Cadastro via edital'
            FROM registration_meta rm
            WHERE r.id = rm.object_id
            AND r.opportunity_id = {$opportunity_id}
            AND rm.key = '$field'
            AND rm.value = '$response'
            AND r.sent_timestamp >= CURRENT_DATE - INTERVAL '6 months';
        ");
    },

    'reenfileiramento do job importRegistrations' => function() {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $query = "
            SELECT * 
            FROM job 
            WHERE name = :name 
                AND status = :status
        ";

        $jobs = $conn->fetchAllAssociative($query, [
            'name' => 'importRegistrations',
            'status' => 1,
        ]);

        foreach ($jobs as $job) {
            $conn->executeQuery(
                "UPDATE job SET status = 0 WHERE id = :id",
                ['id' => $job['id']]
            );
        }
        
        return false;
    },

    'Cria arquivos de logs para inscrições selecionadas ou inválidas da importação que não possuem' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $query = "
            SELECT r.id
            FROM registration r
            WHERE r.status IN (2, 10)
                AND opportunity_id = 5388;
        ";

        $registrations = $conn->fetchAllAssociative($query);

        foreach($registrations as $registration) {
            $registration_id = $registration['id'];

            $dir_path = PUBLIC_PATH . 'files/importer/';
            $log_path = $dir_path . $registration_id . '.log';
            $status_file_path = $dir_path . $registration_id . '_status.json';
            
            if(!is_dir($dir_path)) {
                mkdir($dir_path, 0755, true);
            }
    
            if(!file_exists($log_path)) {
                touch($log_path);
            }
    
            if(!file_exists($status_file_path)) {
                $default_data = [
                    'status' => 0,
                    'message' => '',
                    'timestamp' => date('d-m-Y H:i:s')
                ];

                file_put_contents($status_file_path, json_encode($default_data, JSON_PRETTY_PRINT));
            }
        }
    },
    
    'Popula valor do metadado rcv_last_update_timestamp com o valor da última atualização do coletivo' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $query = "
            WITH pontos AS (
                SELECT a.id, a.update_timestamp
                FROM agent a
                JOIN seal_relation sr ON sr.object_id = a.id 
                    AND sr.object_type = 'MapasCulturais\Entities\Agent'
                    AND sr.seal_id IN (6, 101)
                GROUP BY a.id
            )
            INSERT INTO agent_meta (object_id, key, value)
            SELECT p.id, 'rcv_last_update_timestamp', p.update_timestamp 
            FROM pontos p
            WHERE NOT EXISTS (
                SELECT 1 
                FROM agent_meta am 
                WHERE am.object_id = p.id 
                AND am.key = 'rcv_last_update_timestamp'
            );
        ";

        $conn->executeQuery($query);
    },

    'Atualiza tipoPonto para formato de seleção múltipla' => function () use ($conn) {
        $app = App::i();
        $em = $app->em;
        $conn = $em->getConnection();

        $conn->executeStatement("
            UPDATE agent_meta
            SET value = jsonb_build_array(value)
            WHERE \"key\" = 'tipoPonto'
            AND value IS NOT NULL
            AND TRIM(value) <> ''
        ");
    },

    'Implementa visão para facilitar os filtros com inscrição no metabase' => function() {
         return false;
        $app = App::i();
        $em = $app->em;
        $conn = $em->getConnection();

        $conn->executeQuery("
            CREATE
            OR REPLACE VIEW rcv_bi_registration AS
            select
                r.*,
                uf.value as estado,
                cidade.value as municipio,
                tipoPonto.value as tipo_ponto,
                selo_ponto.id as certificado_ponto,
                selo_pontao.id as certificado_pontao,
                selo_certificacao_minc.id as selo_certificacao_minc,
                aguardando_atualizacao.id as aguardando_atualizacao
            from
                registration r
                join agent_relation ar on ar.object_type = 'MapasCulturais\Entities\Registration'
                and ar.object_id = r.id
                and ar.type = 'coletivo'
                left join seal_relation selo_ponto on selo_ponto.object_type = 'MapasCulturais\Entities\Agent'
                and selo_ponto.object_id = ar.agent_id
                and selo_ponto.seal_id = 6
                left join seal_relation selo_pontao on selo_pontao.object_type = 'MapasCulturais\Entities\Agent'
                and selo_pontao.object_id = ar.agent_id
                and selo_pontao.seal_id = 101
                left join seal_relation selo_certificacao_minc on selo_certificacao_minc.object_type = 'MapasCulturais\Entities\Agent'
                and selo_certificacao_minc.object_id = ar.agent_id
                and selo_certificacao_minc.seal_id = 105
                left join seal_relation aguardando_atualizacao on aguardando_atualizacao.object_type = 'MapasCulturais\Entities\Agent'
                and aguardando_atualizacao.object_id = ar.agent_id
                and aguardando_atualizacao.seal_id = 117
                left join agent_meta uf on uf.key = 'En_Estado'
                and uf.object_id = ar.agent_id
                left join agent_meta cidade on cidade.key = 'En_Municipio'
                and cidade.object_id = ar.agent_id
                left join agent_meta tipoPonto on tipoPonto.key = 'tipoPonto'
                and tipoPonto.object_id = ar.agent_id
            where
                r.opportunity_id = 5386");
    },

    'Altera o tipo dos agentes proprietários de cadastros importados, de coletivo para individual' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $query = "
            SELECT * FROM agent
            WHERE type = 2
                AND id IN (
                    SELECT a.parent_id
                    FROM agent a
                    JOIN seal_relation sr ON sr.object_id = a.id
                        AND sr.object_type = 'MapasCulturais\Entities\Agent'
                        AND sr.seal_id = 105
            );
        ";

        $agents = $conn->fetchAllAssociative($query);

        foreach($agents as $agent) {
            $conn->update('agent', ['type' => 1], ['id' => $agent['id']]);
        }
    },

    'Substituição de inscrições importadas por inscrições feitas diretamente pelo proponente' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();
        $config = include THEMES_PATH . 'CulturaViva/conf-base.php';

        $query = "
            WITH organizacoes_importadas AS (
                SELECT
                    r.id as id_inscricao,
                    r.status as status_inscricao,
                    r.create_timestamp as data_criacao,
                    r.category,
                    r.create_timestamp,
                    r.agent_id as id_responsavel,
                    col.id as id_agente,
                    col.name as nome_coletivo,
                    col.status as status_coletivo,
                    col.status,
                    s.name as Selo,
                    sp.id as id_espaco
                FROM
                    registration r
                    JOIN agent_relation ar ON ar.object_type = 'MapasCulturais\Entities\Registration'
                        AND ar.object_id = r.id
                    JOIN agent col ON col.id = ar.agent_id
                    JOIN seal_relation sr ON sr.object_type = 'MapasCulturais\Entities\Agent' 
                        AND sr.seal_id = 105
                        AND sr.object_id = col.id
                    LEFT JOIN seal s ON s.id = sr.seal_id
                    LEFT JOIN space sp ON sp.agent_id = ar.agent_id
                WHERE
                    r.opportunity_id = 5386
                    AND r.category = 'Ponto de Cultura (coletivo sem CNPJ)'
                    AND col.status = 1
                    AND r.sent_timestamp is null
            )

            SELECT
                r.id as id_inscricao,
                oi.id_inscricao as id_inscricao_importado,
                r.create_timestamp as data_criacao,
                oi.data_criacao as data_criacao_importado,
                r.status as status_inscricao,
                oi.status_inscricao as status_inscricao_importado,
                owner.id as id_responsavel,
                oi.id_responsavel as id_responsável_importado,
                col.id as id_coletivo,
                oi.id_agente as id_coletivo_importado,
                col.name as nome_coletivo,
                oi.nome_coletivo as nome_coletivo_importado,
                col.status as status_coletivo,
                oi.status as status_coletivo_importado,
                nomeCompleto.value as nome_completo,
                oi.id_espaco as id_espaco
            FROM
                registration r
            JOIN agent owner on r.agent_id = owner.id
            JOIN agent_relation ar ON ar.object_type = 'MapasCulturais\Entities\Registration'
                AND ar.object_id = r.id
            JOIN agent col ON col.id = ar.agent_id
            JOIN organizacoes_importadas oi on owner.id = oi.id_responsavel
            LEFT JOIN agent_meta nomeCompleto on nomeCompleto.object_id = col.id and nomeCompleto.key = 'nomeCompleto'
            WHERE
                r.opportunity_id = 5386
                AND r.category = 'Ponto de Cultura (coletivo sem CNPJ)'
                and r.id not in (select id_inscricao from organizacoes_importadas)
                and owner.id in (select id_responsavel from organizacoes_importadas)
                and col.status = 0
        ";

        $cases = $conn->fetchAllAssociative($query);

        $importer_seal = $app->repo('Seal')->find($config['rcv.importerSeal']);
        $waiting_update_seal = $app->repo('Seal')->find($config['rcv.waitingUpdateSeal']);

        $app->hook('entity(Registration).agentRelationsAllowedStatus', function (&$status) {
            $status[] = Agent::STATUS_DRAFT;
        });

        foreach ($cases as $case) {
            if($organization = $app->repo('Agent')->find($case['id_coletivo'])) {
                try {
                    $conn->beginTransaction();

                    $organization->status = Agent::STATUS_ENABLED;
                    $organization->save(true);
                    $app->log->debug("==================");
                    $app->log->debug("Organization {$organization->id} colocado com o status de ativado");

                    if($case['id_espaco']) {
                        $conn->update('space', [
                            'agent_id' => $organization->id
                        ], [
                            'id' => $case['id_espaco']
                        ]);

                        $app->log->debug("Space {$case['id_espaco']} atualizado com o id da organização {$organization->id}");

                        $conn->update('space_relation', [
                            'object_id' => $case['id_inscricao']
                        ], [
                            'object_id' => $case['id_inscricao_importado'],
                            'space_id'  => $case['id_espaco']
                        ]);

                        $app->log->debug("SpaceRelation do Space {$case['id_espaco']} e Registration {$case['id_inscricao_importado']} atualizado com o id da Registration {$case['id_inscricao']}");
                    }

                    if(!$case['id_espaco'] && $organization->publicLocation) {
                        $space = new Space;
                        $space->name = $organization->name ?: $organization->nomeCompleto;
                        $space->owner = $organization;
                        $space->location = $organization->location;
                        $space->type = 125;
                        $space->En_CEP = $organization->En_CEP;
                        $space->En_Nome_Logradouro = $organization->En_Nome_Logradouro;
                        $space->En_Num = $organization->En_Num;
                        $space->En_Bairro = $organization->En_Bairro;
                        $space->En_Municipio = $organization->En_Municipio;
                        $space->En_Estado = $organization->En_Estado;
                        $space->En_Complemento = $organization->En_Complemento ?: '';
                        $space->save(true);

                        $app->log->debug("Criação do space {$space->id}");

                        $registration = $app->repo('Registration')->find($case['id_inscricao']);

                        $relation = new RegistrationSpaceRelation;
                        $relation->space = $space;
                        $relation->owner = $registration;
                        $relation->save(true);

                        $app->log->debug("Criação do SpaceRelation {$relation->id}");

                        $organization->rcv_sede_spaceId = $space->id;
                        $organization->save(true);
                    }

                    $conn->update('registration', [
                        'status' => 10
                    ], [
                        'id' => $case['id_inscricao']
                    ]);

                    $app->log->debug("Atualiza a Registration para aprovado {$case['id_inscricao']}");

                    // Verifica se o usuário já possui os selos de 'Certificado via edital' e 'Aguardando atualização' para aplicar
                    $seal_relations = $organization->getSealRelations();
        
                    $has_importer_seal = false;
                    $has_waiting_update_seal = false;
        
                    foreach($seal_relations as $seal_relation) {
                        if($seal_relation->seal->id == $config['rcv.waitingUpdateSeal']) {
                            $has_waiting_update_seal = true;
                        }
        
                        if($seal_relation->seal->id == $config['rcv.importerSeal']) {
                            $has_importer_seal = true;
                        }
                    }
        
                    if(!$has_waiting_update_seal && $waiting_update_seal) {
                        $organization->createSealRelation($waiting_update_seal, agent: $organization);
                        $app->log->debug("Adiciona selo de Aguardando atualização");
                    }
        
                    if(!$has_importer_seal && $importer_seal) {
                        $organization->createSealRelation($importer_seal, agent: $organization);
                        $app->log->debug("Adiciona selo de Certificado via edital");
                    }

                    // Exclui a inscrição e o agente coletivo criado pela importação
                    $conn->delete('registration', ['id' => $case['id_inscricao_importado']]);
                    $app->log->debug("Deleta a Registration de id {$case['id_inscricao_importado']}");

                    $conn->delete('agent', ['id' => $case['id_coletivo_importado']]);
                    $app->log->debug("Deleta o Agent de id {$case['id_coletivo_importado']}");

                    $conn->commit();
                } catch(Throwable $e) {
                    $app->log->debug("Erro ao processar dados: {$e->getMessage()}");
                    $app->log->debug("Trace: {$e->getTraceAsString()}}");

                    $conn->rollBack();
                }
            }
        }
    },

    'Atualiza o owner das inscrições com o owner correto após trocas de propriedades' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $query = "
                SELECT
                er.user_id as id_usuario,
                a.id as id_agente,
                a.name as nome_agente,
                er.object_id as id_coletivo,
                er.create_timestamp,
                rd.key,
                rd.value as revision_value
            FROM
                entity_revision er
                LEFT JOIN entity_revision_revision_data errd ON errd.revision_id = er.id
                LEFT JOIN entity_revision_data rd ON rd.id = errd.revision_data_id
                LEFT JOIN usr u on er.user_id = u.id
                LEFT JOIN agent a on a.id = u.profile_id
            WHERE
                er.object_type = 'MapasCulturais\Entities\Agent' AND rd.key = 'parent'
                AND er.object_id in (
                    select
                        col.agent_id
                    from
                        registration r 
                    join 
                        agent_relation col on col.object_type = 'MapasCulturais\Entities\Registration' AND col.object_id = r.id AND col.type = 'coletivo'
                    join 
                        seal_relation imp on imp.object_type =  'MapasCulturais\Entities\Agent' AND imp.object_id = col.agent_id and imp.seal_id = 105
                    where
                        r.opportunity_id = 5386 AND
                        r.status = 10
                )
            ORDER BY
                er.create_timestamp ASC;
        ";

        $revisions = $conn->fetchAllAssociative($query);

        $old_agent_id = null;
        foreach($revisions as $revision) {
            $agent_id = $revision['id_coletivo'];
            $current_value = json_decode($revision['revision_value'], true);
            $current_parent_id = $current_value['id'] ?? null;

            if($old_agent_id == $agent_id) {
                $previous_parent_id = $previous_revision_value['id'] ?? null;

                if ($previous_parent_id !== $current_parent_id) {
                    $old_agent_value = $previous_parent_id;
                    $new_agent_value = $current_parent_id;

                    $new_owner = $app->repo('Agent')->find($new_agent_value);
                    if(!$new_owner) {
                        continue;
                    }

                    $registration = $app->repo('Registration')->findOneBy([
                        'opportunity' => 5386,
                        'status' => 10,
                        'owner' => $old_agent_value
                    ]);
                    if(!$registration) {
                        continue;
                    }

                    $actual_owner = $registration->owner->id;

                    $registration->owner = $new_owner;
                    $registration->agentsData = $registration->_getAgentsData();

                    $app->log->debug("[Troca de propriedade] Atualiza o owner {$actual_owner} da inscrição {$registration->id} para o novo owner {$new_owner->id}");

                    $registration->save(true);

                    if($registration_field_config = $registration->opportunity->registrationFieldConfigurations) {
                        foreach($registration_field_config as $field) {
                            if($field->fieldType === 'agent-owner-field') {
                                $field_name = "field_{$field->id}";
                                $conn->executeQuery("DELETE FROM registration_meta WHERE key = '{$field_name}' AND object_id = {$registration->id}");
                            }
                        }
                    }
                }
            }

            $old_agent_id = $agent_id;
            $previous_revision_value = $current_value;
        }
    },

    'Adiciona valor ao sent_timestamp às inscrições criadas pela importação' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $query = "
            SELECT r.id, r.create_timestamp, r.sent_timestamp, r.status
            FROM registration r
            JOIN agent_relation ar 
                ON ar.object_type = 'MapasCulturais\Entities\Registration' 
                AND ar.object_id = r.id
            JOIN agent col 
                ON col.id = ar.agent_id
            JOIN seal_relation sr 
                ON sr.object_type = 'MapasCulturais\Entities\Agent' 
                AND sr.seal_id = 105 
                AND sr.object_id = col.id
            WHERE r.sent_timestamp IS NULL 
                AND r.status = 10 
                AND r.opportunity_id = 5386
        ";

        $registrations = $conn->fetchAllAssociative($query);

        foreach($registrations as $registration) {
            $registration_id = $registration['id'];
            $create_timestamp = $registration['create_timestamp'];

            $conn->update('registration', [
                'sent_timestamp' => $create_timestamp,
            ], ['id' => $registration_id]);
        }
    },

    'Normaliza inscrição de ID 466963538' => function () {
        $app = App::i();
        $em = $app->em;
        /** @var M\Connection $conn */
        $conn = $em->getConnection();

        $registration = $app->repo('Registration')->find(466963538);

        $conn->executeQuery("UPDATE registration SET agent_id = :agent_id WHERE id = :id", [
            'agent_id' => 14621733,
            'id' => $registration->id
        ]);
            
        $agents_data_json = json_encode($registration->_getAgentsData());

        $conn->executeQuery("UPDATE registration SET agents_data = :agents_data WHERE id = :id", [
            'agents_data' => $agents_data_json,
            'id' => $registration->id
        ]);

        if($registration_field_config = $registration->opportunity->registrationFieldConfigurations) {
            foreach($registration_field_config as $field) {
                if($field->fieldType === 'agent-owner-field') {
                    $field_name = "field_{$field->id}";
                    $conn->executeQuery("DELETE FROM registration_meta WHERE key = '{$field_name}' AND object_id = {$registration->id}");
                }
            }
        }
    }
];
