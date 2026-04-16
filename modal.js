// ===== Modal System (Vanilla JS) =====

class Modal {
    constructor(element) {
        this.element = element;
        this.isOpen = false;
        this.init();
    }

    init() {
        // Закрытие по клику на backdrop
        this.element.addEventListener('click', (e) => {
            if (e.target === this.element) {
                this.hide();
            }
        });

        // Закрытие по кнопке close
        const closeBtn = this.element.querySelector('.btn-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.hide());
        }

        // Закрытие по ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen) {
                this.hide();
            }
        });
    }

    show() {
        this.element.classList.add('show');
        this.isOpen = true;
        document.body.style.overflow = 'hidden';

        // Фокус на первый input
        const firstInput = this.element.querySelector('input, button');
        if (firstInput) {
            setTimeout(() => firstInput.focus(), 100);
        }
    }

    hide() {
        this.element.classList.remove('show');
        this.isOpen = false;
        document.body.style.overflow = '';
    }

    static getInstance(element) {
        if (!element._modalInstance) {
            element._modalInstance = new Modal(element);
        }
        return element._modalInstance;
    }
}

// Инициализация всех модальных окон
document.addEventListener('DOMContentLoaded', function() {
    // Инициализация модальных окон
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modalEl => {
        Modal.getInstance(modalEl);
    });

    // Обработка data-modal-toggle
    document.addEventListener('click', function(e) {
        const trigger = e.target.closest('[data-modal-toggle]');
        if (trigger) {
            e.preventDefault();
            const targetId = trigger.getAttribute('data-modal-toggle');
            const modalEl = document.getElementById(targetId);
            if (modalEl) {
                const modal = Modal.getInstance(modalEl);
                modal.show();
            }
        }
    });
});

// Экспорт для использования в других скриптах
window.Modal = Modal;
