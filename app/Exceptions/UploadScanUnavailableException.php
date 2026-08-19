<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A varredura antivírus não pôde ser concluída porque o serviço está fora do ar
 * — o arquivo não foi reprovado, apenas não pôde ser avaliado.
 *
 * Estende {@see ValidationException} para não mudar nada em quem já trata a
 * rejeição de upload: formulários continuam exibindo a mensagem no campo. O tipo
 * próprio existe para quem processa vários arquivos numa requisição: repetir a
 * tentativa para cada arquivo de um lote apenas multiplica o timeout do clamd,
 * então quem enxerga esta exceção deve interromper o restante e oferecer nova
 * tentativa, em vez de reprovar os arquivos.
 */
class UploadScanUnavailableException extends ValidationException {}
