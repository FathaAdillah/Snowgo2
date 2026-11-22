import { readFile, writeFile } from 'node:fs/promises'
import openapiTS, { astToString, COMMENT_HEADER } from 'openapi-typescript'
import ts from 'typescript'

const input = new URL('../../apps/api/api.json', import.meta.url)
const output = new URL('./src/openapi.d.ts', import.meta.url)
const ast = await openapiTS(input, {
  alphabetize: true,
  transform(schema) {
    if (schema.type === 'string' && schema.format === 'binary')
      return ts.factory.createTypeReferenceNode('File')
  }
})
const types = COMMENT_HEADER + astToString(ast)

if (process.argv.includes('--check')) {
  if (types !== await readFile(output, 'utf8'))
    throw new Error('Generated TypeScript differs from OpenAPI. Run make contracts and commit both artifacts.')
}
else {
  await writeFile(output, types)
}
