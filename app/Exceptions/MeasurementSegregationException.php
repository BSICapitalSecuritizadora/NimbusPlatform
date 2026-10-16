<?php

namespace App\Exceptions;

/**
 * Recusa da segregação de funções da medição (maker/checker): quem fez a ação
 * não a confere. É uma recusa do domínio como as do fluxo -- o texto é de
 * operador, em pt-BR, e a tela o mostra como as demais
 * ({@see MeasurementWorkflowException}) --, separada por classe para que a
 * auditoria e os testes a distingam de uma mudança de estado.
 *
 * O contexto carrega só identidades e o controle aplicado: nunca valores,
 * comprovantes ou dados bancários.
 */
class MeasurementSegregationException extends MeasurementWorkflowException {}
