class Expected {
    kind = 'expected';
}

class Actual {
    kind = 'actual';
}

// The annotation types the binding; the class it holds is Actual.
const Backend: typeof Expected = Actual;

export function make(): Expected {
    return new Backend();
}

const Widget = class Widget {
    draw(): void {}
};

export function build(): object {
    return new Widget();
}
