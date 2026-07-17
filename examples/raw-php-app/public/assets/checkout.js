(function () {
    const form = document.querySelector('[data-cart-form]');
    const productsNode = document.getElementById('checkout-products');

    if (!form || !productsNode) {
        return;
    }

    const currency = form.dataset.currency || 'GHS';
    const cartList = form.querySelector('[data-cart-list]');
    const cartPayload = form.querySelector('[data-cart-payload]');
    const amountField = form.querySelector('[data-cart-amount]');
    const totalNode = form.querySelector('[data-cart-total]');
    const submitButton = form.querySelector('[data-cart-submit]');
    const addSelect = form.querySelector('[data-cart-add-select]');
    const addButton = form.querySelector('[data-cart-add]');
    const submitDisabledByConfig = submitButton ? submitButton.disabled : false;

    let products = [];
    let cart = [];

    try {
        products = JSON.parse(productsNode.textContent || '[]');
        cart = JSON.parse(cartPayload.value || '[]');
    } catch (error) {
        return;
    }

    const formatter = new Intl.NumberFormat('en-GH', {
        style: 'currency',
        currency,
        currencyDisplay: 'code',
    });

    function productById(id) {
        return products.find((product) => product.id === id);
    }

    function cartLines() {
        return cart
            .map((item) => {
                const product = productById(item.id);

                if (!product) {
                    return null;
                }

                const quantity = Math.max(1, Math.min(9, Number(item.quantity) || 1));
                const unitPrice = Number(product.price) || 0;

                return {
                    ...product,
                    quantity,
                    total: unitPrice * quantity,
                };
            })
            .filter(Boolean);
    }

    function total() {
        return cartLines().reduce((sum, line) => sum + line.total, 0);
    }

    function syncFields() {
        const nextTotal = total();
        cartPayload.value = JSON.stringify(cart);
        amountField.value = nextTotal.toFixed(2);
        totalNode.textContent = formatter.format(nextTotal);

        if (submitButton) {
            submitButton.disabled = submitDisabledByConfig || cart.length === 0;
            submitButton.textContent = cart.length === 0
                ? 'Add an item to pay'
                : `Pay ${formatter.format(nextTotal)} ->`;
        }
    }

    function createButton(className, label, action, itemName) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.dataset[action] = '';
        button.textContent = label;

        if (itemName) {
            button.setAttribute('aria-label', `${label === '-' ? 'Decrease' : 'Increase'} ${itemName}`);
        }

        return button;
    }

    function renderCart() {
        const lines = cartLines();
        cartList.textContent = '';

        if (lines.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'cart-empty';
            empty.textContent = 'Your cart is empty. Add a product to continue.';
            cartList.append(empty);
            syncFields();
            return;
        }

        lines.forEach((line) => {
            const item = document.createElement('article');
            item.className = 'cart-item';
            item.dataset.cartItem = line.id;

            const image = document.createElement('img');
            image.src = line.image;
            image.alt = line.name;

            const copy = document.createElement('div');
            copy.className = 'cart-copy';

            const name = document.createElement('strong');
            name.textContent = line.name;

            const description = document.createElement('span');
            description.textContent = line.description;

            copy.append(name, description);

            const price = document.createElement('div');
            price.className = 'cart-price';

            const totalPrice = document.createElement('strong');
            totalPrice.textContent = formatter.format(line.total);

            const unitPrice = document.createElement('span');
            unitPrice.textContent = `${formatter.format(line.price)} each`;

            price.append(totalPrice, unitPrice);

            const quantity = document.createElement('div');
            quantity.className = 'quantity-control';
            quantity.setAttribute('aria-label', `${line.name} quantity`);

            const quantityText = document.createElement('span');
            quantityText.dataset.cartQuantity = '';
            quantityText.textContent = String(line.quantity);

            quantity.append(
                createButton('icon-button', '-', 'cartDecrease', line.name),
                quantityText,
                createButton('icon-button', '+', 'cartIncrease', line.name)
            );

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'remove-button';
            remove.dataset.cartRemove = '';
            remove.textContent = 'Remove';

            item.append(image, copy, price, quantity, remove);
            cartList.append(item);
        });

        syncFields();
    }

    function updateQuantity(id, direction) {
        cart = cart
            .map((item) => {
                if (item.id !== id) {
                    return item;
                }

                return {
                    ...item,
                    quantity: Number(item.quantity || 1) + direction,
                };
            })
            .filter((item) => item.quantity > 0);

        renderCart();
    }

    cartList.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        const item = event.target.closest('[data-cart-item]');

        if (!button || !item) {
            return;
        }

        if (button.matches('[data-cart-increase]')) {
            updateQuantity(item.dataset.cartItem, 1);
        }

        if (button.matches('[data-cart-decrease]')) {
            updateQuantity(item.dataset.cartItem, -1);
        }

        if (button.matches('[data-cart-remove]')) {
            cart = cart.filter((cartItem) => cartItem.id !== item.dataset.cartItem);
            renderCart();
        }
    });

    addButton.addEventListener('click', () => {
        const id = addSelect.value;
        const existing = cart.find((item) => item.id === id);

        if (existing) {
            existing.quantity = Math.min(9, Number(existing.quantity || 1) + 1);
        } else {
            cart.push({ id, quantity: 1 });
        }

        renderCart();
    });

    renderCart();
}());
