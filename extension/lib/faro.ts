/**
 * Reports something that went wrong quietly (YouTube markup that no longer
 * matches, a failed send). A no-op until Faro is configured.
 */
export function reportIssue(
    _name: string,
    _attributes: Record<string, string> = {},
): void {}
