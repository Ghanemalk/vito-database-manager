// Settings remembered in the browser (page size, hidden columns, the SQL draft…), per server and database.

export const storage = {
  get(k, d) {
    try {
      const v = localStorage.getItem(k);
      return v === null ? d : JSON.parse(v);
    } catch {
      return d;
    }
  },
  set(k, v) {
    try {
      localStorage.setItem(k, JSON.stringify(v));
    } catch {
      // storage full or disabled: these are conveniences only
    }
  },
};
