<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings de idioma para local_sm_estratoos_plugin (Português Brasileiro).
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Geral.
$string['pluginname'] = 'Tokens de Empresa SmartMind - Estratoos';
$string['plugindescription'] = 'Criar e gerenciar tokens de API com escopo por empresa para instalações multi-tenant do SmartMind - Estratoos.';

// Capacidades.
$string['sm_estratoos_plugin:managetokens'] = 'Gerenciar todos os tokens SmartMind';
$string['sm_estratoos_plugin:managecompanytokens'] = 'Gerenciar tokens de uma empresa';
$string['sm_estratoos_plugin:createbatch'] = 'Criar tokens em lote';
$string['sm_estratoos_plugin:viewreports'] = 'Ver relatórios de tokens';
$string['sm_estratoos_plugin:export'] = 'Exportar tokens';
$string['sm_estratoos_plugin:createtokensapi'] = 'Criar tokens via API';

// Painel de controle.
$string['dashboard'] = 'Painel de Gerenciamento de Tokens';
$string['dashboarddesc'] = 'Criar e gerenciar tokens de API para sua instalação SmartMind - Estratoos.';
$string['createadmintoken'] = 'Criar Token de Administrador';
$string['createadmintokendesc'] = 'Criar um token em nível de sistema para o administrador do Moodle com acesso completo.';
$string['createcompanytokens'] = 'Criar Tokens de Empresa';
$string['createcompanytokensdesc'] = 'Criar tokens com escopo de empresa para usuários em lote. Estes tokens retornarão apenas dados da empresa selecionada.';
$string['managetokens'] = 'Gerenciar Tokens';
$string['managetokensdesc'] = 'Ver, editar e revogar tokens existentes.';

// Página de token de administrador.
$string['admintoken'] = 'Token de Administrador';
$string['admintokendesc'] = 'Criar um token em nível de sistema para o administrador do site. Este token terá acesso completo a todos os dados.';
$string['createadmintokenbutton'] = 'Criar Token de Administrador';
$string['admintokencreated'] = 'Token de administrador criado com sucesso';
$string['admintokenwarning'] = 'Atenção: Este token fornece acesso completo ao sistema. Mantenha-o seguro!';

// Página de tokens em lote.
$string['batchtokens'] = 'Criação de Tokens em Lote';
$string['batchtokensdesc'] = 'Criar tokens para múltiplos usuários de uma vez com acesso limitado à empresa.';
$string['createbatchtokens'] = 'Criar Tokens em Lote';

// Seleção de usuários.
$string['userselection'] = 'Seleção de Usuários';
$string['selectionmethod'] = 'Método de seleção';
$string['bycompany'] = 'Por empresa';
$string['bycsv'] = 'Upload de CSV';
$string['company'] = 'Empresa';
$string['selectcompany'] = 'Selecionar empresa';
$string['department'] = 'Departamento';
$string['alldepartments'] = 'Todos os departamentos';
$string['csvfile'] = 'Arquivo CSV';
$string['csvfield'] = 'Campo CSV para identificação do usuário';
$string['userid'] = 'ID do Usuário';
$string['csvhelp'] = 'Faça upload de um arquivo CSV com um identificador de usuário por linha. A primeira linha pode ser um cabeçalho.';
$string['csvhelp_help'] = 'O arquivo CSV deve conter um identificador de usuário por linha. Você pode usar IDs de usuário, nomes de usuário ou endereços de e-mail. Se a primeira linha for um cabeçalho, ela será automaticamente ignorada.';

// Seleção de serviço.
$string['serviceselection'] = 'Serviço Web';
$string['service'] = 'Serviço';
$string['selectservice'] = 'Selecionar serviço web';
$string['noservicesenabled'] = 'Nenhum serviço web está habilitado. Por favor, habilite pelo menos um serviço web.';

// Restrições de token.
$string['tokenrestrictions'] = 'Restrições do Token';
$string['restricttocompany'] = 'Restringir à empresa';
$string['restricttocompany_desc'] = 'Quando habilitado, chamadas de API retornarão apenas dados da empresa selecionada.';
$string['restricttoenrolment'] = 'Restringir à inscrição';
$string['restricttoenrolment_desc'] = 'Quando habilitado, usuários verão apenas cursos em que estão inscritos (além do filtro de empresa).';

// Configurações de lote.
$string['batchsettings'] = 'Configurações do Lote';
$string['iprestriction'] = 'Restrição de IP';
$string['iprestriction_help'] = 'Insira endereços IP ou faixas permitidos (separados por vírgula). Deixe vazio para sem restrição. Exemplos: 192.168.1.1, 10.0.0.0/8';
$string['validuntil'] = 'Válido até';
$string['validuntil_help'] = 'Defina uma data de expiração para os tokens. Deixe vazio para tokens que nunca expiram.';
$string['neverexpires'] = 'Nunca expira';

// Configurações individuais.
$string['individualoverrides'] = 'Substituições Individuais';
$string['allowindividualoverrides'] = 'Permitir configurações individuais de tokens';
$string['allowindividualoverrides_desc'] = 'Quando habilitado, você pode modificar restrições de IP e validade para tokens individuais após a criação.';

// Notas.
$string['notes'] = 'Notas';
$string['notes_help'] = 'Notas opcionais sobre este lote ou token para fins administrativos.';

// Ações.
$string['createtokens'] = 'Criar Tokens';
$string['cancel'] = 'Cancelar';
$string['back'] = 'Voltar';
$string['revoke'] = 'Revogar';
$string['revokeselected'] = 'Revogar Selecionados';
$string['export'] = 'Exportar';
$string['exportselected'] = 'Exportar Selecionados';
$string['exportcsv'] = 'Exportar como CSV';
$string['edit'] = 'Editar';
$string['delete'] = 'Excluir';
$string['apply'] = 'Aplicar';
$string['filter'] = 'Filtrar';

// Resultados.
$string['batchcomplete'] = 'Criação de tokens em lote concluída';
$string['tokenscreated'] = '{$a} tokens criados com sucesso';
$string['tokensfailed'] = '{$a} tokens falharam ao criar';
$string['errors'] = 'Erros';
$string['createdtokens'] = 'Tokens Criados';
$string['tokensshownonce'] = 'As strings de token são mostradas apenas uma vez. Certifique-se de salvá-las antes de sair desta página.';
$string['batchid'] = 'ID do Lote';
$string['createnewbatch'] = 'Criar Novo Lote';
$string['recentbatches'] = 'Lotes Recentes';
$string['createdby'] = 'Criado por';

// Lista de tokens.
$string['tokenlist'] = 'Lista de Tokens';
$string['notokens'] = 'Nenhum token encontrado';
$string['token'] = 'Token';
$string['tokens'] = 'tokens';
$string['user'] = 'Usuário';
$string['restrictions'] = 'Restrições';
$string['companyonly'] = 'Apenas empresa';
$string['enrolledonly'] = 'Apenas inscritos';
$string['lastaccess'] = 'Último acesso';
$string['actions'] = 'Ações';
$string['bulkactions'] = 'Ações em massa...';
$string['selectall'] = 'Selecionar tudo';

// Mensagens de confirmação.
$string['confirmrevoke'] = 'Tem certeza de que deseja revogar este token? Esta ação não pode ser desfeita.';
$string['confirmrevokeselected'] = 'Tem certeza de que deseja revogar os tokens selecionados? Esta ação não pode ser desfeita.';
$string['tokenrevoked'] = 'Token revogado com sucesso';
$string['tokensrevoked'] = '{$a} tokens revogados com sucesso';

// Mensagens de erro.
$string['accessdenied'] = 'Acesso negado. Apenas administradores do site podem acessar esta página.';
$string['invalidcompany'] = 'Empresa selecionada inválida';
$string['invalidservice'] = 'Serviço selecionado inválido';
$string['usernotincompany'] = 'O usuário {$a->userid} não é membro da empresa {$a->companyid}';
$string['coursenotincompany'] = 'Este curso não pertence à sua empresa';
$string['usernotenrolled'] = 'Você não está inscrito neste curso';
$string['invalidtoken'] = 'Token inválido';
$string['tokennotfound'] = 'Token não encontrado';
$string['invalidiprestriction'] = 'Formato de restrição de IP inválido';
$string['csverror'] = 'Erro ao processar arquivo CSV: {$a}';
$string['nousersfound'] = 'Nenhum usuário encontrado que corresponda aos critérios';
$string['emptycsv'] = 'O arquivo CSV está vazio ou não contém usuários válidos';

// Configurações.
$string['settings'] = 'Configurações de Tokens SmartMind';
$string['defaultvaliditydays'] = 'Validade padrão (dias)';
$string['defaultvaliditydays_desc'] = 'Número padrão de dias antes dos tokens expirarem. Defina como 0 para tokens que nunca expiram.';
$string['cleanupexpiredtokens'] = 'Limpar tokens expirados';
$string['cleanupexpiredtokens_desc'] = 'Remover automaticamente registros de tokens de empresa expirados durante o cron.';
$string['defaultrestricttocompany'] = 'Padrão: Restringir à empresa';
$string['defaultrestricttocompany_desc'] = 'Valor padrão para restrição de empresa ao criar novos tokens.';
$string['defaultrestricttoenrolment'] = 'Padrão: Restringir à inscrição';
$string['defaultrestricttoenrolment_desc'] = 'Valor padrão para restrição de inscrição ao criar novos tokens.';

// Privacidade.
$string['privacy:metadata:local_sm_estratoos_plugin'] = 'Informações sobre tokens com escopo de empresa';
$string['privacy:metadata:local_sm_estratoos_plugin:tokenid'] = 'O ID do token externo';
$string['privacy:metadata:local_sm_estratoos_plugin:companyid'] = 'A empresa à qual este token está limitado';
$string['privacy:metadata:local_sm_estratoos_plugin:createdby'] = 'O usuário que criou este token';
$string['privacy:metadata:local_sm_estratoos_plugin:timecreated'] = 'Quando o token foi criado';

// Tarefas.
$string['task:cleanupexpiredtokens'] = 'Limpar tokens de empresa expirados';

// Seleção de usuários.
$string['quickselect'] = 'Seleção rápida';
$string['selectallusers'] = 'Todos';
$string['selectnone'] = 'Nenhum';
$string['selectstudents'] = 'Estudantes';
$string['selectteachers'] = 'Professores';
$string['selectmanagers'] = 'Gestores';
$string['selectedusers'] = 'usuários selecionados';
$string['searchusers'] = 'Pesquisar usuários...';
$string['loadingusers'] = 'Carregando usuários...';
$string['nousersselected'] = 'Por favor selecione pelo menos um usuário';
$string['companymanager'] = 'Gestor de Empresa';

// Detecção do IOMAD.
$string['iomaddetected'] = 'Modo multi-tenant IOMAD detectado';
$string['standardmoodle'] = 'Modo Moodle padrão (sem empresas)';
$string['moodlemode'] = 'Modo Moodle';

// Modo sem IOMAD.
$string['createusertokens'] = 'Criar Tokens de Usuário';
$string['createusertokensdesc'] = 'Criar tokens de API para usuários em lote.';
$string['selectusers'] = 'Selecionar Usuários';
$string['allusers'] = 'Todos os usuários';
$string['searchandselect'] = 'Pesquisar e selecionar usuários';
$string['nousersavailable'] = 'Nenhum usuário disponível';
