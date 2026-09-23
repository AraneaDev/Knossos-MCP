export class Scoring {
  formatTime(t: number): string {
    return String(t);
  }
}

export class Level {
  private scoring: any;

  constructor(config: any) {
    this.scoring = config.scoring || null;
  }

  formatTime(t: number): string {
    return `${t}`;
  }

  helper(): number {
    return 1;
  }

  complete(): string {
    const format =
      this.scoring?.formatTime?.bind(this.scoring) ||
      this.formatTime.bind(this);
    return format(1) + this.helper.call(this);
  }
}

export function standalone(): number {
  return 2;
}
export const applied = standalone.apply(null, []);
