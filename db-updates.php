<?php

use MapasCulturais\App;
use MapasCulturais as M;
use MapasCulturais\Entities\Registration;
use CulturaViva\JobTypes\JobsAFormTextUpdater;

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

        return false;
    }
];
