// What the framework's package exports for its components' global, reduced
// to what the fixture reads.
export interface AstroGlobal<Props = Record<string, any>> {
  props: Props;
  params: Record<string, string | undefined>;
  [member: string]: any;
}
