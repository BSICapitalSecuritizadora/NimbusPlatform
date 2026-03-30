/**
 * NimbusDocs Utilities
 */
document.addEventListener('DOMContentLoaded', function () {

    // Mask CPF
    const applyCpfMask = (value) => {
        return value
            .replace(/\D/g, '') // Remove tudo que não é dígito
            .replace(/(\d{3})(\d)/, '$1.$2')
            .replace(/(\d{3})(\d)/, '$1.$2')
            .replace(/(\d{3})(\d{1,2})/, '$1-$2')
            .replace(/(-\d{2})\d+?$/, '$1');
    };

    const cpfInputs = document.querySelectorAll('[data-mask="cpf"]');
    cpfInputs.forEach(input => {
        input.addEventListener('input', (e) => {
            e.target.value = applyCpfMask(e.target.value);
        });

        // Apply on load if has value
        if (input.value) {
            input.value = applyCpfMask(input.value);
        }
    });

    // Mask Phone
    const applyPhoneMask = (value) => {
        value = value.replace(/\D/g, "");
        value = value.replace(/^(\d{2})(\d)/g, "($1) $2");
        value = value.replace(/(\d)(\d{4})$/, "$1-$2");
        return value;
    };

    const phoneInputs = document.querySelectorAll('[data-mask="phone"]');
    phoneInputs.forEach(input => {
        input.addEventListener('input', (e) => {
            e.target.value = applyPhoneMask(e.target.value);
        });
        if (input.value) {
            input.value = applyPhoneMask(input.value);
        }
    });

});
